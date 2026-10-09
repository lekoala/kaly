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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;

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

    public function testSecretsNeverLeakThroughDumpsOrSerialization(): void
    {
        $secret = Secret::fromBase64Url(self::SECRET);
        $raw = (string) Base64Url::decode(self::SECRET);

        $this->assertStringNotContainsString($raw, print_r($secret, true));
        $this->assertStringContainsString('[redacted]', print_r($secret, true));

        $hmac = new Hmac($secret, 'dump:v1');
        $this->assertSame(['purpose' => 'dump:v1'], $hmac->__debugInfo());

        foreach ([$secret, $hmac] as $object) {
            try {
                serialize($object);
                $this->fail('Serialization must be refused');
            } catch (LogicException $e) {
                $this->assertStringEndsWith('cannot be serialized', $e->getMessage());
            }
        }
    }

    public function testHmacSignsWithAStableFormat(): void
    {
        $hmac = new Hmac(Secret::fromBase64Url(self::SECRET), 'auth-verification:v1');
        $message = json_encode([42, '+32470000000', '123456'], JSON_THROW_ON_ERROR);

        $key = hash_hkdf('sha256', (string) Base64Url::decode(self::SECRET), 32, 'kaly.hmac:auth-verification:v1');
        $expected = Base64Url::encode(hash_hmac('sha256', $message, $key, true));

        $this->assertSame($expected, $hmac->sign($message));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $hmac->sign($message));
        $this->assertTrue($hmac->verify($message, $hmac->sign($message)));
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
