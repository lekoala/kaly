<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Core\LocalizedExceptionHandler;
use Kaly\Di\Definitions;
use Kaly\Http\ExceptionHandler;
use Kaly\Http\ExceptionHandlerInterface;
use Kaly\Http\Input\ValidationException;
use Kaly\I18n\Adapter\SymfonyTranslator;
use Kaly\I18n\LocalizedTranslator;
use Kaly\I18n\Translatable;
use Kaly\I18n\TranslatableValidationException;
use Kaly\I18n\TranslationKey;
use Kaly\I18n\Translator;
use Kaly\I18n\TranslatorInterface;
use Kaly\Tests\Support\HttpFactory;
use Kaly\Util\Json;
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
        $translator->addToCatalog('validation', 'en', ['required' => 'Field %field% is required']);
        $translator->addToCatalog('validation', 'fr', ['required' => 'Le champ %field% est requis']);
        $translator->addToCatalog('validation', 'nl', ['required' => 'Veld %field% is verplicht']);
        return $translator;
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

    public function testLiteralValidationExceptionIsUntouched(): void
    {
        $exception = new ValidationException('Custom validation failed');

        // A literal is not Translatable: the localized handler keeps its body
        // unchanged, see testHandlerKeepsLiteralValidationErrorsUntouched.
        $this->assertSame('Custom validation failed', $exception->getMessage());
        $this->assertSame('Custom validation failed', $exception->getResponseBody());
    }

    public function testKeyedValidationExceptionPropagatesIdDomainAndParameters(): void
    {
        $translator = $this->translator();
        $exception = new TranslatableValidationException(TypedTranslationKey::Required, parameters: ['%field%' => 'email']);

        $this->assertSame(TypedTranslationKey::Required, $exception->translation());
        $this->assertSame(['%field%' => 'email'], $exception->parameters());
        // getMessage stays diagnosable without i18n: the raw id
        $this->assertSame('validation.required', $exception->getMessage());

        $this->assertSame('Le champ email est requis', $exception->translate(new LocalizedTranslator($translator, 'fr')));
        $this->assertSame('Veld email is verplicht', (new LocalizedTranslator($translator, 'nl'))->resolve($exception));
    }

    public function testSameExceptionResolvedInTwoLocalesDoesNotLeak(): void
    {
        $translator = $this->translator();
        $exception = new TranslatableValidationException(TypedTranslationKey::Hello);

        $fr = new LocalizedTranslator($translator, 'fr');
        $nl = new LocalizedTranslator($translator, 'nl');

        $this->assertSame('Message de test', $fr->resolve($exception));
        $this->assertSame('Testbericht', $nl->resolve($exception));
        $this->assertSame('Message de test', $fr->resolve($exception));
    }

    public function testResolvePropagatesNonNullDomain(): void
    {
        $spy = new SpyTranslator();
        $i18n = new LocalizedTranslator($spy, 'fr');

        $this->assertSame('translated:required', $i18n->resolve(TypedTranslationKey::Namespaced));
        $this->assertSame([['required', [], 'validation', 'fr']], $spy->calls);
    }

    public function testKeyedValidationExceptionPropagatesItsDomain(): void
    {
        $spy = new SpyTranslator();
        $i18n = new LocalizedTranslator($spy, 'fr');
        $exception = new TranslatableValidationException(TypedTranslationKey::Namespaced, parameters: ['%field%' => 'email']);

        $this->assertSame('translated:required', $exception->translate($i18n));
        $this->assertSame([['required', ['%field%' => 'email'], 'validation', 'fr']], $spy->calls);
        $this->assertSame('Le champ email est requis', $exception->translate(new LocalizedTranslator($this->translator(), 'fr')));
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
        $this->assertSame('{{typed.missing}}', (new LocalizedTranslator($native, 'en'))->resolve(TypedTranslationKey::Missing));

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

    public function testHandlerTranslatesPublicValidationErrors(): void
    {
        $exception = new TranslatableValidationException(TypedTranslationKey::Required, parameters: ['%field%' => 'email']);

        $fr = $this->handler()->toResponse($exception, $this->localizedRequest('fr'));
        $this->assertSame(422, $fr->getStatusCode());
        $this->assertSame('Le champ email est requis', (string) $fr->getBody());

        $nl = $this->handler()->toResponse($exception, $this->localizedRequest('nl'));
        $this->assertSame('Veld email is verplicht', (string) $nl->getBody());
    }

    public function testHandlerFallsBackWithoutAResolvedLocale(): void
    {
        $exception = new TranslatableValidationException(TypedTranslationKey::Required, parameters: ['%field%' => 'email']);

        $response = $this->handler()->toResponse($exception, new ServerRequest('GET', '/'));
        $this->assertSame('validation.required', (string) $response->getBody());
    }

    public function testHandlerKeepsLiteralValidationErrorsUntouched(): void
    {
        $response = $this->handler()->toResponse(new ValidationException('This is invalid'), $this->localizedRequest('fr'));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('This is invalid', (string) $response->getBody());
    }

    public function testHandlerProducesLocalizedProblemJson(): void
    {
        $exception = new TranslatableValidationException(TypedTranslationKey::Required, parameters: ['%field%' => 'email']);
        $request = $this->localizedRequest('fr')->withHeader('Accept', 'application/json');

        $response = $this->handler()->toResponse($exception, $request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(ExceptionHandler::PROBLEM_JSON, $response->getHeaderLine('Content-Type'));
        $problem = Json::decodeMap((string) $response->getBody());
        $this->assertSame('Le champ email est requis', $problem['detail']);
        $this->assertSame(422, $problem['status']);
        $this->assertArrayNotHasKey('exception', $problem);
    }

    public function testDebugProblemJsonKeepsTheOriginalExceptionInTheChain(): void
    {
        $psr17 = new Psr17Factory();
        $handler = new LocalizedExceptionHandler(new ExceptionHandler($psr17, $psr17, debug: true), $this->translator());
        $exception = new TranslatableValidationException(TypedTranslationKey::Required, parameters: ['%field%' => 'email']);
        $request = $this->localizedRequest('fr')->withHeader('Accept', 'application/problem+json');

        $problem = Json::decodeMap((string) $handler->toResponse($exception, $request)->getBody());

        $this->assertSame('Le champ email est requis', $problem['detail']);
        $this->assertIsArray($problem['exception']);
        $classes = array_column($problem['exception'], 'class');
        $this->assertContains(TranslatableValidationException::class, $classes);
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
        $app = App::create(__DIR__)->debug(false);

        $response = $this->get($app, '/test-module/index/validation/');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('This is invalid', (string) $response->getBody());

        $this->assertInstanceOf(LocalizedExceptionHandler::class, $app->container()->get(ExceptionHandlerInterface::class));
    }

    public function testRebindingRestoresHistoricalBodies(): void
    {
        $app = App::create(__DIR__)->debug(false);
        $app->configure(static function (Definitions $definitions): void {
            $definitions->rebind(ExceptionHandlerInterface::class, ExceptionHandler::class);
        });

        $response = $this->get($app, '/test-module/index/validation/');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('This is invalid', (string) $response->getBody());
        $this->assertInstanceOf(ExceptionHandler::class, $app->container()->get(ExceptionHandlerInterface::class));
        $this->assertNotInstanceOf(LocalizedExceptionHandler::class, $app->container()->get(ExceptionHandlerInterface::class));

        // A keyed error also falls back to its raw id without the decorator
        $psr17 = new Psr17Factory();
        $historical = new ExceptionHandler($psr17, $psr17);
        $typed = new TranslatableValidationException(TypedTranslationKey::Required, parameters: ['%field%' => 'email']);
        $this->assertSame('validation.required', (string) $historical->toResponse($typed, $this->localizedRequest('fr'))->getBody());
    }
}
