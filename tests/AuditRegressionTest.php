<?php

declare(strict_types=1);

namespace Kaly\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Kaly\Clock\FrozenClock;
use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\Middleware\Registry;
use Kaly\Ex;
use Kaly\Http\Accept;
use Kaly\Http\AcceptLanguage;
use Kaly\Http\Cookie\SetCookieHeader;
use Kaly\Http\Exception\BadRequestException;
use Kaly\Http\Exception\RedirectException;
use Kaly\Http\Exception\UnauthorizedException;
use Kaly\Http\ExceptionHandler;
use Kaly\Http\Input\InputMapper;
use Kaly\Http\ServerRequestFromGlobals;
use Kaly\Http\Session\SessionCookie;
use Kaly\I18n\Locale;
use Kaly\I18n\LocaleResolver;
use Kaly\I18n\Translator;
use Kaly\Log\FileLogger;
use Kaly\Router\ConventionResolver;
use Kaly\Router\RouteCollection;
use Kaly\Router\RouteDefinition;
use Kaly\Router\RouteRequest;
use Kaly\Router\TrailingSlash;
use Kaly\Test\MemorySessionProvider;
use Kaly\Tests\Mocks\PaginationInput;
use Kaly\Tests\Mocks\RouteHandlerFixture;
use Kaly\Util\Str;
use Kaly\Validation\Validator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class AuditRegressionTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    public function testStaticRoutesPrecedeDynamicRoutesWhateverTheirPlaceholderName(): void
    {
        $collection = new RouteCollection([
            new RouteDefinition('/users/{slug}', RouteHandlerFixture::class, 'slug'),
            new RouteDefinition('/users/new', RouteHandlerFixture::class, '__invoke'),
        ]);
        $this->assertSame('/users/new', $collection->toArray()[0]['path']);
    }

    public function testLiteralRoutePunctuationAndConstraintDelimiters(): void
    {
        $collection = new RouteCollection([
            new RouteDefinition('/feed.xml', RouteHandlerFixture::class, '__invoke'),
            new RouteDefinition('/tags/{slug}', RouteHandlerFixture::class, 'slug', requirements: ['slug' => '[a-z#]+']),
        ]);
        $entries = $collection->entries();
        $this->assertSame(1, preg_match($entries[0]['regex'], '/feed.xml'));
        $this->assertSame(0, preg_match($entries[0]['regex'], '/feedaxml'));
        $this->assertSame(1, preg_match($entries[1]['regex'], '/tags/a#b'));
    }

    public function testInvalidRouteConstraintFailsDuringCompilation(): void
    {
        $this->expectException(Ex::class);
        new RouteCollection([
            new RouteDefinition('/{slug}', RouteHandlerFixture::class, 'slug', requirements: ['slug' => '[']),
        ]);
    }

    public function testRegionalAndUppercaseDefaultLocalesTerminateAndRespectFallbackOrder(): void
    {
        foreach (['en-US', 'fr_BE', 'FR'] as $locale) {
            $translator = new Translator($locale);
            $translator->addToCatalog('messages', Locale::language($locale), ['language' => 'Language']);
            $translator->addToCatalog('messages', $locale, ['regional' => 'Region']);
            $this->assertSame('Missing Alice', $translator->translate('Missing %name%', ['%name%' => 'Alice'], locale: 'nl-BE'));
            $this->assertSame('Region', $translator->translate('regional', locale: 'nl'));
            $this->assertSame('Language', $translator->translate('language', locale: 'nl'));
        }
    }

    public function testTranslationDomainsCannotEscapeTheirDirectory(): void
    {
        $this->expectException(Ex::class);
        (new Translator())->translate('secret', domain: '../secret');
    }

    public function testMissingCatalogsAreNotRetainedAcrossWorkerRequests(): void
    {
        $translator = new Translator();
        for ($index = 0; $index < 100; $index++) {
            $translator->translate('missing', locale: 'en-x-' . $index);
        }
        $catalogs = new \ReflectionProperty($translator, 'catalogs');
        $this->assertSame([], $catalogs->getValue($translator));
    }

    public function testMalformedSessionCookiesAreIgnoredAndUnknownMemoryIdsStayLazy(): void
    {
        foreach (['invalid!chars', str_repeat('a', 129), ['array'], 42] as $value) {
            $request = (new ServerRequest('GET', '/'))->withCookieParams(['PHPSESSID' => $value]);
            $this->assertNull(SessionCookie::idFromRequest($request, 'PHPSESSID'));
        }
        $provider = new MemorySessionProvider(['name' => 'PHPSESSID']);
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['PHPSESSID' => 'attacker-chosen-id']);
        $session = $provider->create($request);
        $this->assertInstanceOf(\Kaly\Http\Session\ArraySession::class, $session);
        $this->assertNull($session->get('user'));
        $this->assertFalse($session->isActive());
        $this->assertNull($session->getId());
        $session->set('user', '42');
        $this->assertNotSame('attacker-chosen-id', $session->getId());
    }

    public function testJsonRedirectsKeepTheirPublicBodyWithoutDiagnostics(): void
    {
        $psr17 = new Psr17Factory();
        $handler = new ExceptionHandler($psr17, $psr17, debug: true);
        $redirect = new RedirectException('/login');
        $response = $handler->toResponse($redirect, new ServerRequest('GET', '/', ['Accept' => 'application/json']));
        $this->assertSame(307, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertSame($redirect->getResponseBody(), (string) $response->getBody());
        $this->assertFalse($response->hasHeader('Content-Type'));
    }

    public function testHeaderExceptionsRejectControlCharactersBeforePsr7(): void
    {
        foreach ([
            static fn(): RedirectException => new RedirectException("/login\r\nInjected: yes"),
            static fn(): UnauthorizedException => new UnauthorizedException("Basic\nInjected: yes"),
        ] as $create) {
            try {
                $create();
                $this->fail('Expected invalid header value to be refused');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('control characters', $exception->getMessage());
            }
        }
    }

    public function testAbsoluteRequestTargetsCannotOverrideSapiAuthorityOrScheme(): void
    {
        $psr17 = new Psr17Factory();
        $factory = new ServerRequestFromGlobals($psr17, $psr17, $psr17, $psr17);
        $request = $factory->create(
            server: ['REQUEST_URI' => 'https://evil.test//login?q=1', 'HTTP_HOST' => '[::1]', 'SERVER_PORT' => '8080'],
            query: [],
            post: [],
            cookies: [],
            files: [],
        );
        $this->assertSame('http://[::1]:8080//login?q=1', (string) $request->getUri());
    }

    public function testMalformedHostIsAClientFailure(): void
    {
        $psr17 = new Psr17Factory();
        $factory = new ServerRequestFromGlobals($psr17, $psr17, $psr17, $psr17);
        $this->expectException(BadRequestException::class);
        $factory->create(server: ['HTTP_HOST' => 'evil.test@victim.test'], query: [], post: [], cookies: [], files: []);
    }

    public function testSpecificRefusalsOverrideGenericAcceptRanges(): void
    {
        $accept = Accept::parse('text/*;q=1, text/html;q=0');
        $this->assertFalse($accept->accepts('text', 'html'));
        $this->assertNotContains('text/html', $accept->toArray());
        $languages = AcceptLanguage::parse('en-US;q=0, en;q=0.8, *;q=0.5');
        $this->assertSame(0.0, $languages->qualityFor('en-US'));
        $this->assertSame('en', $languages->best(['en-US', 'en']));
        $this->assertSame(0.8, AcceptLanguage::parse('en-GB;q=0, *;q=0.8')->qualityFor('en'));
    }

    public function testInputConflictsOnlyAffectDeclaredFieldsAndProduceOneViolation(): void
    {
        $mapper = new InputMapper();
        $request = (new ServerRequest('POST', '/'))->withQueryParams(['unknown' => 'a'])->withParsedBody(['unknown' => 'b']);
        $this->assertTrue($mapper->mapResult($request, PaginationInput::class)->isValid());
        $request = $request->withQueryParams(['page' => 'bad'])->withParsedBody(['page' => '2']);
        $violations = $mapper->mapResult($request, PaginationInput::class)->validation()->violations();
        $this->assertCount(1, $violations);
        $this->assertSame('conflicting_values', $violations[0]->code);
        $request = $request->withQueryParams([])->withHeader('Content-Type', 'application/json')->withParsedBody(['page' => '']);
        $this->assertSame('type_int', $mapper->mapResult($request, PaginationInput::class)->validation()->violations()[0]->code);
    }

    public function testNumericRegionAndLocaleCase(): void
    {
        $this->assertSame('419', Locale::parse('es-419')['country']);
        $resolver = new LocaleResolver('en', ['en', 'fr-BE']);
        $this->assertSame('fr-BE', $resolver->resolve(new ServerRequest('GET', '/', ['Accept-Language' => 'fr-be'])));
    }

    public function testNanFailsRangeValidation(): void
    {
        $validator = new Validator();
        $validator->between('number', NAN, min: 1, max: 10);
        $this->assertFalse($validator->result()->isValid());
    }

    public function testStringHelpersPreserveValuesAndUnicode(): void
    {
        $this->assertSame('42', Str::stringify(42));
        $this->assertSame('3.14', Str::stringify(3.14));
        $this->assertJson(Str::stringify(['invalid' => "\xff", 'number' => NAN]));
        $this->assertSame('FooBar', Str::camelize('fooBar'));
        $this->assertSame('éclair', Str::camelize('Éclair', false));
        $this->assertSame('', Str::camelize('-', false));
    }

    public function testLoggerAndCookieExpiryUseTheClockAndCannotForgeLogLines(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T12:00:00Z'));
        $file = tempnam(sys_get_temp_dir(), 'kaly-audit-log-');
        $this->assertNotFalse($file);
        try {
            $logger = new FileLogger($file, clock: $clock);
            $logger->info('User {id}: {message}', ['id' => 42, 'message' => "hello\r\n[forged]"]);
            $this->assertSame("[2026-01-01 12:00:00] [info] User 42: hello\\r\\n[forged]\n", file_get_contents($file));
        } finally {
            unlink($file);
        }
        $this->assertStringContainsString('Expires=Thu, 01 Jan 2026 12:01:00 GMT', SetCookieHeader::build(
            'name',
            'value',
            ['lifetime' => 60],
            clock: $clock,
        ));
    }

    public function testRegistryRejectsMutationAfterBoot(): void
    {
        $app = (new App(__DIR__))
            ->routing(TrailingSlash::Preserve, true)
            ->boot();
        $registry = $app->container()->get(Registry::class);
        $this->expectException(Ex::class);
        $registry->clear();
    }

    public function testNumericAndMissingControllersDoNotRedirectToThemselves(): void
    {
        $app = (new App(__DIR__))->routing(TrailingSlash::Preserve, true);
        foreach (['123', 'NotFoundPage'] as $part) {
            $response = $app->handle(new ServerRequest('GET', '/test-module/' . $part));
            $this->assertSame(404, $response->getStatusCode());
            $this->assertFalse($response->hasHeader('Location'));
        }
    }

    public function testConventionRedirectTargetsTheConsumedSegmentAfterARepeatedPrefix(): void
    {
        $app = (new App(__DIR__))
            ->routing(TrailingSlash::Preserve, true)
            ->boot();
        $resolver = new ConventionResolver();
        $request = new ServerRequest('GET', '/index/demo/index');
        try {
            $resolver->resolve(new RouteRequest($request, ['demo', 'index'], '/index', 'TestModule'));
            $this->fail('Expected a canonical redirect');
        } catch (RedirectException $exception) {
            $this->assertSame('/index/demo', $exception->getUrl());
        }
        $this->assertTrue($app->isBooted());
    }

    public function testComposerPsr4PathsAcceptTrailingSlashesAndPathListsWithoutExtraAutoloaders(): void
    {
        $base = sys_get_temp_dir() . '/kaly-audit-psr4-' . uniqid();
        \Kaly\Util\Fs::ensureDir($base . '/modules/Booking/src');
        \Kaly\Util\Fs::putFile(
            $base . '/modules/Booking/config.php',
            '<?php return static function (\\Kaly\\Core\\Module $module): void {};',
        );
        try {
            foreach (['modules/Booking/src/', ['modules/Booking/src/', 'legacy/']] as $paths) {
                \Kaly\Util\Fs::putFile($base . '/composer.json', \Kaly\Util\Json::encode([
                    'autoload' => ['psr-4' => ['Booking\\' => $paths]],
                ]));
                $before = spl_autoload_functions();
                $app = (new App($base, false))->locales(['fr', 'nl'])->boot();
                $this->assertSame($before, spl_autoload_functions());
                $translator = $app->container()->get(\Kaly\I18n\TranslatorInterface::class);
                $this->assertInstanceOf(Translator::class, $translator);
                $this->assertSame('fr', $translator->getDefaultLocale());
            }
        } finally {
            \Kaly\Tests\Support\TempDir::remove($base);
        }
    }

    public function testLocalizedRedirectIsRelativeAndAppliesTrailingSlashPolicy(): void
    {
        $app = (new App(__DIR__))->locales(['fr', 'en'])->routing(TrailingSlash::Add, true);
        $response = $app->handle(new ServerRequest('GET', 'https://untrusted.test/lang-module/index/getlang/?foo=bar'));
        $this->assertSame('/fr/lang-module/index/getlang/?foo=bar', $response->getHeaderLine('Location'));
    }

    public function testAllNullQueryValuesDoNotAppendAQuestionMark(): void
    {
        $app = (new App(__DIR__))
            ->routing(TrailingSlash::Add, true)
            ->boot();
        $router = $app->container()->get(\Kaly\Router\RouterInterface::class);
        $this->assertSame('/test-module/alias/hello/', $router->url('test-module:alias.hello', ['filter' => null]));
    }

    public function testIndexIsAnInvokableControllerArgumentWhenThereIsNoIndexAction(): void
    {
        $app = (new App(__DIR__))->routing(TrailingSlash::Preserve, true);
        $response = $app->handle(new ServerRequest('GET', '/test-module/slug/index'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('index', (string) $response->getBody());
    }

    public function testCookieValuesAreDecodedBeforeTheyAreReplayed(): void
    {
        $app = (new App(__DIR__))->routing(TrailingSlash::Preserve, true);
        $app->middleware()->outgoing(static fn(\Psr\Http\Message\ResponseInterface $response): \Psr\Http\Message\ResponseInterface => $response->withHeader('Set-Cookie', SetCookieHeader::build(
            'name',
            'a+b %',
            [],
        )));
        $app->boot();
        $client = \Kaly\Test\TestClient::for($app);
        $client->get('/');
        $this->assertSame(['name' => 'a+b %'], $client->cookies());
    }

    public function testUppercaseDefaultActionRedirectsWithoutLooping(): void
    {
        $app = (new App(__DIR__))->routing(TrailingSlash::Preserve, true);
        $response = $app->handle(new ServerRequest('GET', '/test-module/demo/Index'));
        $this->assertSame(307, $response->getStatusCode());
        $this->assertSame('/test-module/demo', $response->getHeaderLine('Location'));
    }
}
