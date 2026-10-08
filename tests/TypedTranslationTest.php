<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Core\LocalizedExceptionHandler;
use Kaly\Core\ViolationMessageResolver;
use Kaly\Di\Definitions;
use Kaly\Http\ExceptionHandler;
use Kaly\Http\ExceptionHandlerInterface;
use Kaly\Http\Input\ValidationException;
use Kaly\I18n\Adapter\SymfonyTranslator;
use Kaly\I18n\LocalizedTranslator;
use Kaly\I18n\Translatable;
use Kaly\I18n\TranslationKey;
use Kaly\I18n\Translator;
use Kaly\I18n\TranslatorInterface;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Support\HttpFactory;
use Kaly\Util\Json;
use Kaly\Validation\Validator;
use Kaly\Validation\Violation;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator as SymfonyEngine;

enum TypedTranslationKey: string implements TranslationKey
{
    case Hello = 'global.test';
    case Required = 'validation.required';
    case Missing = 'typed.missing';
    case Namespaced = 'required';

    public function id(): string
    {
        return $this->value;
    }

    public function domain(): ?string
    {
        return match ($this) {
            self::Namespaced => 'validation',
            default => null,
        };
    }
}

final class SpyTranslator implements TranslatorInterface
{
    /** @var list<array{0:string,1:array<string,mixed>,2:?string,3:?string}> */
    public array $calls = [];

    public function translate(string $message, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $this->calls[] = [$message, $parameters, $domain, $locale];
        return "translated:{$message}";
    }
}

class TypedTranslationTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    private function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addToCatalog('messages', 'en', [
            'global.test' => 'Test message',
            'validation.required' => 'Field %field% is required',
        ]);
        $translator->addToCatalog('messages', 'fr', [
            'global.test' => 'Message de test',
            'validation.required' => 'Le champ %field% est requis',
        ]);
        $translator->addToCatalog('messages', 'nl', [
            'global.test' => 'Testbericht',
            'validation.required' => 'Veld %field% is verplicht',
        ]);
        $translator->addToCatalog('validation', 'en', [
            'required' => 'Field %field% is required',
            'email' => 'This email address is not valid',
        ]);
        $translator->addToCatalog('validation', 'fr', [
            'required' => 'Le champ %field% est requis',
            'email' => 'Cette adresse e-mail n’est pas valide',
        ]);
        $translator->addToCatalog('validation', 'nl', [
            'required' => 'Veld %field% is verplicht',
            'email' => 'Dit e-mailadres is niet geldig',
        ]);
        return $translator;
    }

    private function emailException(): ValidationException
    {
        $validator = new Validator();
        $validator->email('email', 'not-an-email');
        return new ValidationException($validator->result());
    }

    public function testResolveLeavesStringsLiteralWithoutCallingTheEngine(): void
    {
        $spy = new SpyTranslator();
        $i18n = new LocalizedTranslator($spy, 'fr');

        $this->assertSame('missing', $i18n->resolve('missing'));
        $this->assertSame('hello', $i18n->resolve('hello'));
        $this->assertSame([], $spy->calls);
    }

    public function testResolveTranslatesAKeyByIdAndDomain(): void
    {
        $spy = new SpyTranslator();
        $i18n = new LocalizedTranslator($spy, 'fr');

        $this->assertSame('translated:global.test', $i18n->resolve(TypedTranslationKey::Hello));
        $this->assertSame([['global.test', [], null, 'fr']], $spy->calls);
    }

    public function testResolveDelegatesToTranslatableWithTheCurrentTranslator(): void
    {
        $spy = new SpyTranslator();
        $i18n = new LocalizedTranslator($spy, 'fr');
        $translatable = new class() implements Translatable {
            public ?LocalizedTranslator $seen = null;

            public function translate(LocalizedTranslator $i18n): string
            {
                $this->seen = $i18n;
                return 'custom:' . $i18n->locale();
            }
        };

        $this->assertSame('custom:fr', $i18n->resolve($translatable));
        $this->assertSame($i18n, $translatable->seen);
        $this->assertSame([], $spy->calls);
    }

    public function testTranslatableWinsOverTranslationKey(): void
    {
        $spy = new SpyTranslator();
        $i18n = new LocalizedTranslator($spy, 'en');
        $both = new class() implements TranslationKey, Translatable {
            public function id(): string
            {
                return 'global.test';
            }

            public function domain(): ?string
            {
                return null;
            }

            public function translate(LocalizedTranslator $i18n): string
            {
                return 'translatable wins';
            }
        };

        $this->assertSame('translatable wins', $i18n->resolve($both));
        $this->assertSame([], $spy->calls);
    }

    public function testViolationWithoutTranslationUsesItsFallback(): void
    {
        $violation = new Violation('email', 'email', 'email', 'This value is not a valid email address');

        $this->assertSame('email', $violation->code);
        $this->assertSame('email', $violation->messageId);
        $this->assertSame('validation', $violation->domain);
        $this->assertSame('This value is not a valid email address', $this->emailException()->getResponseBody());
    }

    public function testViolationResolverUsesTheCatalogByIdAndDomain(): void
    {
        $resolver = new ViolationMessageResolver(new LocalizedTranslator($this->translator(), 'fr'));
        $violation = new Violation('email', 'email', 'email', 'fallback', domain: 'validation');

        $this->assertSame('Cette adresse e-mail n’est pas valide', $resolver->resolve($violation));
    }

    public function testViolationResolverFormatsParameters(): void
    {
        $resolver = new ViolationMessageResolver(new LocalizedTranslator($this->translator(), 'en'));
        $violation = new Violation('email', 'required', 'required', 'fallback', ['%field%' => 'email'], 'validation');

        $this->assertSame('Field email is required', $resolver->resolve($violation));
    }

    public function testViolationResolverFallsBackWhenTheKeyIsMissing(): void
    {
        $resolver = new ViolationMessageResolver(new LocalizedTranslator($this->translator(), 'fr'));
        $violation = new Violation('name', 'custom_code', 'untranslated.key', 'Fallback text', domain: 'app');

        $this->assertSame('Fallback text', $resolver->resolve($violation));
    }

    public function testResolvePropagatesNonNullDomain(): void
    {
        $spy = new SpyTranslator();
        $i18n = new LocalizedTranslator($spy, 'fr');

        $this->assertSame('translated:required', $i18n->resolve(TypedTranslationKey::Namespaced));
        $this->assertSame([['required', [], 'validation', 'fr']], $spy->calls);
    }

    public function testTypedKeysAreCompleteAcrossCatalogs(): void
    {
        $translator = $this->translator();
        foreach (TypedTranslationKey::cases() as $case) {
            if ($case === TypedTranslationKey::Missing) {
                continue;
            }
            foreach (['en', 'fr', 'nl'] as $locale) {
                $this->assertNotSame(
                    '{{' . $case->id() . '}}',
                    $translator->translate($case->id(), [], $case->domain(), $locale),
                    "missing {$case->id()} for {$locale}",
                );
            }
        }
    }

    public function testMissingKeysKeepEngineBehaviour(): void
    {
        $native = $this->translator();
        $this->assertSame('typed.missing', (new LocalizedTranslator($native, 'en'))->resolve('typed.missing'));
        $this->assertSame('typed.missing', (new LocalizedTranslator($native, 'en'))->resolve(TypedTranslationKey::Missing));

        $engine = new SymfonyEngine('en');
        $engine->addLoader('php', new PhpFileLoader());
        $engine->addResource('php', __DIR__ . '/data/lang/messages.en.php', 'en');
        $symfony = new LocalizedTranslator(new SymfonyTranslator($engine), 'en');
        $this->assertSame('typed.missing', $symfony->resolve('typed.missing'));
        $this->assertSame('typed.missing', $symfony->resolve(TypedTranslationKey::Missing));
    }

    private function localizedRequest(string $locale): ServerRequest
    {
        $request = new ServerRequest('GET', '/');
        $ctx = new HttpContext($request);
        $ctx->useLocale($locale);
        $bound = $ctx->bind($request);
        assert($bound instanceof ServerRequest);
        return $bound;
    }

    private function handler(): LocalizedExceptionHandler
    {
        $psr17 = new Psr17Factory();
        return new LocalizedExceptionHandler(new ExceptionHandler($psr17, $psr17), $this->translator());
    }

    public function testHandlerTranslatesEachViolationWithoutLosingItsStructure(): void
    {
        $validator = new Validator();
        $validator->email('email', 'not-an-email');
        $validator->add(new Violation('name', 'custom_code', 'untranslated.key', 'Fallback text', domain: 'app'));
        $exception = new ValidationException($validator->result());

        $response = $this->handler()->toResponse($exception, $this->localizedRequest('fr'));

        $this->assertSame(422, $response->getStatusCode());
        // The first message stays the plain-text body for non-JSON clients
        $this->assertSame('Cette adresse e-mail n’est pas valide', (string) $response->getBody());
    }

    public function testHandlerFallsBackWithoutAResolvedLocale(): void
    {
        $response = $this->handler()->toResponse($this->emailException(), new ServerRequest('GET', '/'));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('This value is not a valid email address', (string) $response->getBody());
    }

    public function testHandlerProducesLocalizedProblemJson(): void
    {
        $validator = new Validator();
        $validator->email('email', 'not-an-email');
        $validator->add(new Violation(null, 'invalid_period', 'invalid_period', 'The selected period is not valid', domain: 'booking'));
        $request = $this->localizedRequest('fr')->withHeader('Accept', 'application/json');

        $response = $this->handler()->toResponse(new ValidationException($validator->result()), $request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(ExceptionHandler::PROBLEM_JSON, $response->getHeaderLine('Content-Type'));
        $problem = Json::decodeMap((string) $response->getBody());
        $this->assertSame(422, $problem['status']);
        $this->assertArrayNotHasKey('detail', $problem);
        $this->assertArrayNotHasKey('exception', $problem);
        $this->assertSame(
            [
                ['field' => 'email', 'code' => 'email', 'message' => 'Cette adresse e-mail n’est pas valide'],
                // No booking catalog: the fallback is used, the structure is kept
                ['field' => null, 'code' => 'invalid_period', 'message' => 'The selected period is not valid'],
            ],
            $problem['errors'],
        );
    }

    public function testHandlerKeepsFallbacksUntouchedWithoutALocale(): void
    {
        $psr17 = new Psr17Factory();
        $handler = new ExceptionHandler($psr17, $psr17);
        $request = new ServerRequest('GET', '/', ['Accept' => 'application/json']);

        $problem = Json::decodeMap((string) $handler->toResponse($this->emailException(), $request)->getBody());

        $this->assertSame(
            [
                ['field' => 'email', 'code' => 'email', 'message' => 'This value is not a valid email address'],
            ],
            $problem['errors'],
        );
    }

    public function testDebugProblemJsonKeepsTheOriginalExceptionInTheChain(): void
    {
        $psr17 = new Psr17Factory();
        $handler = new LocalizedExceptionHandler(new ExceptionHandler($psr17, $psr17, debug: true), $this->translator());
        $request = $this->localizedRequest('fr')->withHeader('Accept', 'application/problem+json');

        $problem = Json::decodeMap((string) $handler->toResponse($this->emailException(), $request)->getBody());

        $errors = $problem['errors'] ?? null;
        $this->assertIsArray($errors);
        $this->assertCount(1, $errors);
        $row = $errors[0];
        $this->assertIsArray($row);
        $this->assertSame('Cette adresse e-mail n’est pas valide', $row['message']);
        $this->assertIsArray($problem['exception']);
        $classes = array_column($problem['exception'], 'class');
        $this->assertContains(ValidationException::class, $classes);
    }

    public function testTranslatableAloneNeverBecomesPublic(): void
    {
        $failure = new class('secret exploded') extends \RuntimeException implements Translatable {
            public function translate(LocalizedTranslator $i18n): string
            {
                return 'translated secret';
            }
        };

        $response = $this->handler()->toResponse($failure, $this->localizedRequest('fr'));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Server error', (string) $response->getBody());
    }

    private function get(App $app, string $path, string $accept = 'text/html'): ResponseInterface
    {
        return $app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withHeader('Accept', $accept));
    }

    public function testAppBindsTheLocalizedHandler(): void
    {
        $app = App::create(__DIR__)->routing(TrailingSlash::Add, true)->debug(false);

        $response = $this->get($app, '/test-module/index/validation/');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('This value must not be blank', (string) $response->getBody());

        $this->assertInstanceOf(LocalizedExceptionHandler::class, $app->container()->get(ExceptionHandlerInterface::class));
    }

    public function testRebindingRestoresHistoricalBodies(): void
    {
        $app = App::create(__DIR__)->routing(TrailingSlash::Add, true)->debug(false);
        $app->configure(static function (Definitions $definitions): void {
            $definitions->rebind(ExceptionHandlerInterface::class, ExceptionHandler::class);
        });

        $response = $this->get($app, '/test-module/index/validation/');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('This value must not be blank', (string) $response->getBody());
        $this->assertInstanceOf(ExceptionHandler::class, $app->container()->get(ExceptionHandlerInterface::class));
        $this->assertNotInstanceOf(LocalizedExceptionHandler::class, $app->container()->get(ExceptionHandlerInterface::class));

        // A violation also falls back to its fallback without the decorator
        $psr17 = new Psr17Factory();
        $historical = new ExceptionHandler($psr17, $psr17);
        $this->assertSame(
            'This value is not a valid email address',
            (string) $historical->toResponse($this->emailException(), $this->localizedRequest('fr'))->getBody(),
        );
    }

    public function testRebuiltExceptionsKeepTheChain(): void
    {
        $exception = $this->emailException();
        $rebuilt = new ValidationException($exception->validation(), $exception);

        $this->assertInstanceOf(ValidationException::class, $rebuilt);
        $this->assertFalse($rebuilt->validation()->isValid());
        $this->assertSame($exception, $rebuilt->getPrevious());
    }
}
