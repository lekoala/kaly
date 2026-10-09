<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Crypto\Hmac;
use Kaly\Crypto\Secret;
use Kaly\Ex;
use Kaly\Router\TrailingSlash;
use Kaly\Util\Base64Url;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Symfony\Component\VarDumper\Cloner\VarCloner;

class CryptoTest extends TestCase
{
    // A public test vector: the bytes 0x00 to 0x1f
    // @mago-expect lint:no-literal-password
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    protected function tearDown(): void
    {
        unset($_ENV[App::ENV_SECRET]);
        ErrorHandler::restoreDefaults();
    }

    public function testGeneratedSecretsAreRandomAndReadable(): void
    {
        $encoded = Secret::generate();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $encoded);
        $this->assertNotSame($encoded, Secret::generate());
        $this->assertInstanceOf(Secret::class, Secret::fromBase64Url($encoded));
    }

    public function testShortSecretsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A secret must contain at least 32 bytes');
        Secret::fromBase64Url(Base64Url::encode(str_repeat('a', 31)));
    }

    public function testMalformedSecretsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A secret must be base64url encoded');
        Secret::fromBase64Url('not+base64url/');
    }

    public function testDerivedKeysDependOnThePurpose(): void
    {
        $secret = Secret::fromBase64Url(self::SECRET);

        $this->assertSame(32, strlen($secret->deriveKey('a')));
        $this->assertSame($secret->deriveKey('a'), Secret::fromBase64Url(self::SECRET)->deriveKey('a'));
        $this->assertNotSame($secret->deriveKey('a'), $secret->deriveKey('b'));
        $this->assertSame(64, strlen($secret->deriveKey('a', 64)));
    }

    public function testDerivationRejectsAnEmptyPurposeOrAWeakLength(): void
    {
        $secret = Secret::fromBase64Url(self::SECRET);
        try {
            $secret->deriveKey('');
            $this->fail('An empty purpose must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('A key purpose cannot be empty', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $secret->deriveKey('a', 8);
    }

    public function testNativeDumpsShowNoKeyMaterial(): void
    {
        $secret = new Secret(str_repeat('K', 32));
        $hmac = new Hmac($secret, 'dump:v1');

        $this->assertSame("Kaly\\Crypto\\Secret Object\n(\n    [bytes] => [redacted]\n)\n", print_r($secret, true));
        $this->assertSame("Kaly\\Crypto\\Hmac Object\n(\n    [purpose] => dump:v1\n)\n", print_r($hmac, true));
        $this->assertSame(['purpose' => 'dump:v1'], (array) $hmac);
        $this->assertSame([], (array) $secret);
    }

    public function testSymfonyDumpsShowNoKeyMaterial(): void
    {
        // VarDumper reads private properties besides __debugInfo(): only the
        // values below may appear, never the bytes or a derived key.
        $secret = new Secret(str_repeat('K', 32));
        $cloner = new VarCloner();

        $this->assertSame(['[redacted]'], array_values((array) $cloner->cloneVar($secret)->getValue(true)));
        $this->assertSame(['dump:v1'], array_values((array) $cloner->cloneVar(new Hmac($secret, 'dump:v1'))->getValue(true)));
    }

    public function testSecretsAndSigningKeysCannotBeSerializedOrCloned(): void
    {
        $secret = Secret::fromBase64Url(self::SECRET);
        $hmac = new Hmac($secret, 'dump:v1');

        // Static analysis does not see __clone() behind the clone operator
        $clone = static fn(object $object): object => clone $object;

        foreach ([$secret, $hmac] as $object) {
            try {
                serialize($object);
                $this->fail('Serialization must be refused');
            } catch (LogicException $e) {
                $this->assertStringEndsWith('cannot be serialized', $e->getMessage());
            }
            try {
                $this->fail('Cloning must be refused, got ' . $clone($object)::class);
            } catch (LogicException $e) {
                $this->assertStringEndsWith('cannot be cloned', $e->getMessage());
            }
        }
    }

    /**
     * Frozen reference vectors: signatures may be persisted or cross deployments,
     * so the derivation and the encoding are a compatibility contract. A failure
     * here means existing signatures would stop verifying: never update the
     * expected values without a versioning or migration plan.
     */
    #[DataProvider('referenceVectors')]
    public function testHmacMatchesFrozenReferenceVectors(string $message, string $signature): void
    {
        $hmac = new Hmac(Secret::fromBase64Url(self::SECRET), 'test-vector:v1');

        $this->assertSame($signature, $hmac->sign($message));
        $this->assertTrue($hmac->verify($message, $signature));
    }

    /** @return array<string,array{string,string}> */
    public static function referenceVectors(): array
    {
        return [
            'text' => ['Kaly HMAC test vector', 'd0V-ftOoDunDPyYdfAqLV6_zA9DCEILzDvxa7NGLF1s'],
            'binary' => ["\x00\x01\xFE\xFF", 'HsmWDx_Jt07z4ntrCYVgI6dC88YdgZaEaMyAx7-kTXc'],
        ];
    }

    public function testHmacRejectsAnotherMessagePurposeOrSecret(): void
    {
        $secret = Secret::fromBase64Url(self::SECRET);
        $hmac = new Hmac($secret, 'form-protection:v1');
        $signature = $hmac->sign('payload');

        $this->assertFalse($hmac->verify('payload ', $signature));
        $this->assertFalse($hmac->verify('payload', ''));
        $this->assertFalse($hmac->verify('payload', strtoupper($signature)));
        $this->assertFalse((new Hmac($secret, 'auth-verification:v1'))->verify('payload', $signature));
        $other = new Hmac(Secret::fromBase64Url(Secret::generate()), 'form-protection:v1');
        $this->assertFalse($other->verify('payload', $signature));
    }

    public function testAppResolvesTheSecretFromTheEnvironmentOnDemand(): void
    {
        $_ENV[App::ENV_SECRET] = self::SECRET;
        $app = (new App(__DIR__, false))
            ->routing(TrailingSlash::Add, true)
            ->boot();

        $secret = $app->container()->get(Secret::class);

        $this->assertInstanceOf(Secret::class, $secret);
        $this->assertSame(Secret::fromBase64Url(self::SECRET)->deriveKey('a'), $secret->deriveKey('a'));
    }

    public function testAppBootsWithoutASecretButRefusesToResolveIt(): void
    {
        $this->assertSecretFailure('', 'APP_SECRET is not configured');
    }

    public function testAppReportsAnInvalidSecret(): void
    {
        $this->assertSecretFailure(Base64Url::encode('short'), 'APP_SECRET is invalid: A secret must contain at least 32 bytes');
    }

    private function assertSecretFailure(string $value, string $message): void
    {
        $_ENV[App::ENV_SECRET] = $value;
        $app = (new App(__DIR__, false))
            ->routing(TrailingSlash::Add, true)
            ->boot();

        try {
            $app->container()->get(Secret::class);
            $this->fail('The secret must not resolve');
        } catch (ContainerExceptionInterface $e) {
            $previous = $e->getPrevious();
            $this->assertInstanceOf(Ex::class, $previous);
            $this->assertSame($message, $previous->getMessage());
        }
    }
}
