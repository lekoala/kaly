<?php

declare(strict_types=1);

namespace Kaly\Core;

use Closure;
use InvalidArgumentException;
use Kaly\Asset\AssetPublisher;
use Kaly\Asset\Assets;
use Kaly\Asset\AssetsInterface;
use Kaly\Asset\AssetSources;
use Kaly\Clock\SystemClock;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Http\CookiePolicy;
use Kaly\Http\ExceptionHandler;
use Kaly\Http\ExceptionHandlerInterface;
use Kaly\Http\HttpContext;
use Kaly\Http\InputMapper;
use Kaly\Http\InputMapperInterface;
use Kaly\Http\NativePhpSessionProvider;
use Kaly\Http\Psr17Discovery;
use Kaly\Http\ResponseEmitter;
use Kaly\Http\ServerRequestFromGlobals;
use Kaly\Http\SessionProviderInterface;
use Kaly\I18n\LocaleResolver;
use Kaly\I18n\Translator;
use Kaly\I18n\TranslatorInterface;
use Kaly\Log\FileLogger;
use Kaly\Middleware\Builtin\FileServer;
use Kaly\Middleware\MiddlewareBand;
use Kaly\Middleware\MiddlewareRegistry;
use Kaly\Middleware\MiddlewareRunner;
use Kaly\Middleware\OutgoingRunner;
use Kaly\Middleware\RouteMiddlewareRunner;
use Kaly\Router\RequestDispatcher;
use Kaly\Router\Router;
use Kaly\Router\RouterInterface;
use Kaly\Router\RoutingHandler;
use Kaly\Util\Env;
use Kaly\Util\Fs;
use Kaly\Util\Json;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * A kaly application.
 *
 * ```php
 * // public/index.php
 * require __DIR__ . '/../vendor/autoload.php';
 *
 * Kaly\Core\App::create(dirname(__DIR__))->run();
 * ```
 *
 * The app owns the lifecycle (env, modules, container, boot); the request
 * cycle is delegated to a stateless Kernel, so a booted app can handle many
 * requests (workers, Fibers). Configuration happens before boot, through
 * typed hooks and the middleware registry:
 *
 * ```php
 * $app = App::create(__DIR__)
 *     ->configure(fn(Definitions $di) => $di->bind(Mailer::class, SmtpMailer::class))
 *     ->onError(fn(Throwable $e, HttpContext $ctx) => $sentry->capture($e));
 * $app->middleware()->routed(Authenticate::class);
 * ```
 */
final class App implements RequestHandlerInterface
{
    // Named entries in di
    public const DEBUG_LOGGER = 'debugLogger';
    // Env params
    public const IGNORE_DOT_ENV = 'IGNORE_DOT_ENV';
    public const ENV_DEBUG = 'APP_DEBUG';
    public const ENV_TIMEZONE = 'APP_TIMEZONE';
    public const ENV_LOCALES = 'APP_LOCALES';
    public const ENV_IDE_PLACEHOLDER = 'DUMP_IDE_PLACEHOLDER';

    private const DEFAULT_IMPLEMENTATIONS = [
        // PSR-20
        ClockInterface::class => SystemClock::class,
        // PSR-3
        LoggerInterface::class => NullLogger::class,
        // Our interfaces
        ExceptionHandlerInterface::class => ExceptionHandler::class,
        SessionProviderInterface::class => NativePhpSessionProvider::class,
        TranslatorInterface::class => Translator::class,
        InputMapperInterface::class => InputMapper::class,
        AssetsInterface::class => Assets::class,
    ];

    private Paths $paths;
    private Hooks $hooks;
    private MiddlewareRegistry $middleware;
    private bool $debug = false;
    /**
     * @var list<string>
     */
    private array $locales = [];
    private bool $booted = false;
    /**
     * @var list<Module>
     */
    private array $modules = [];
    private ?Container $container = null;
    private ?Kernel $kernel = null;

    /**
     * It only needs the base directory that contains the conventional folders
     * (see Paths).
     *
     * It will look for a .env file in the base directory except if the
     * IGNORE_DOT_ENV env flag is set. APP_DEBUG and APP_TIMEZONE are applied.
     */
    public function __construct(string $dir, bool $loadEnv = true)
    {
        if (!is_dir($dir)) {
            throw new InvalidArgumentException("Base directory '{$dir}' does not exist");
        }

        $this->paths = new Paths($dir);
        $this->hooks = new Hooks();
        $this->middleware = new MiddlewareRegistry();

        $envFile = $this->paths->base . '/.env';
        if ($loadEnv && !Env::getBool(self::IGNORE_DOT_ENV) && is_file($envFile)) {
            Env::load($envFile);
        }

        if (Env::has(self::ENV_DEBUG)) {
            $this->debug = Env::getBool(self::ENV_DEBUG);
        }
        if (Env::has(self::ENV_LOCALES)) {
            $this->locales = array_values(array_filter(array_map(trim(...), explode(',', Env::getString(self::ENV_LOCALES)))));
        }
        // Without APP_TIMEZONE Kaly leaves the global timezone alone:
        // php.ini or a date_default_timezone_set() done before boot survives.
        // An empty APP_TIMEZONE value falls back to 'UTC' via getString().
        if (Env::has(self::ENV_TIMEZONE)) {
            date_default_timezone_set(Env::getString(self::ENV_TIMEZONE, 'UTC'));
        }
    }

    public static function create(string $dir, bool $loadEnv = true): self
    {
        return new self($dir, $loadEnv);
    }

    // region Configuration

    /**
     * Force the debug mode, driven by APP_DEBUG by default
     */
    public function debug(bool $debug = true): self
    {
        $this->assertNotBooted('debug mode');
        $this->debug = $debug;
        return $this;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * The locales of the application, the first one is the default. Driven by
     * APP_LOCALES (eg: `fr,en`) by default. A module opts in with
     * `$module->localized()` to get the locale prefix in its urls.
     *
     * @param list<string> $locales
     */
    public function locales(array $locales): self
    {
        $this->assertNotBooted('locales');
        $this->locales = array_values($locales);
        return $this;
    }

    /**
     * @return list<string>
     */
    public function getLocales(): array
    {
        return $this->locales;
    }

    /**
     * Compose the container once every module contributed. Anything set here
     * wins over the modules and over the framework defaults.
     *
     * @param Closure(Definitions): void $configure
     */
    public function configure(Closure $configure): self
    {
        $this->assertNotBooted('container');
        $this->hooks->configure[] = $configure;
        return $this;
    }

    /**
     * @param Closure(App): void $hook Runs once the app is booted
     */
    public function onBoot(Closure $hook): self
    {
        $this->assertNotBooted('boot hooks');
        $this->hooks->boot[] = $hook;
        return $this;
    }

    /**
     * Report generic errors (HTTP exceptions are expected outcomes and skipped).
     * A failing hook never prevents the error response.
     *
     * @param Closure(Throwable, HttpContext): void $hook
     */
    public function onError(Closure $hook): self
    {
        $this->hooks->error[] = $hook;
        return $this;
    }

    /**
     * Runs once the cycle is over, with the final response available as
     * `$ctx->response()`. Ideal to flush logs or metrics.
     *
     * @param Closure(HttpContext): void $hook
     */
    public function onTerminate(Closure $hook): self
    {
        $this->hooks->terminate[] = $hook;
        return $this;
    }

    /**
     * The middleware configuration. It can be used before or after boot:
     *
     * ```php
     * $app->middleware()
     *     ->incoming(TrustedProxy::class)
     *     ->routed(AuthMiddleware::class, priority: 100)
     *     ->outgoing(SecurityHeaders::class, always: true);
     * ```
     */
    public function middleware(): MiddlewareRegistry
    {
        return $this->middleware;
    }

    // endregion

    // region Lifecycle

    /**
     * Load the modules, build the container and the request kernel.
     * Called automatically by handle() and run().
     */
    public function boot(): self
    {
        if ($this->booted) {
            throw new LogicException('App is already booted');
        }
        $this->booted = true;

        ErrorHandler::configureDefaults($this->debug);

        if ($this->debug) {
            $this->paths->ensureAll();
        }

        $this->container = new Container($this->buildDefinitions());
        $this->kernel = new Kernel(
            $this->createRequestHandler(),
            $this->container->get(ExceptionHandlerInterface::class),
            $this->hooks,
            new OutgoingRunner($this->container, $this->middleware, $this->hooks->error(...)),
            $this->container->get(SessionProviderInterface::class),
            $this->container->get(CookiePolicy::class),
        );

        if ($this->debug) {
            $this->hooks->terminate[] = $this->logPipeline(...);
        }

        $this->hooks->boot($this);

        return $this;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Handle a request and return its response. Boots the app if needed.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->booted) {
            $this->boot();
        }

        return $this->getKernel()->handle($request);
    }

    /**
     * The SAPI entry point: build the request from the globals (unless one is
     * given), handle it and emit the response. A failure during boot still
     * produces a proper 500.
     */
    public function run(?ServerRequestInterface $request = null): void
    {
        try {
            if (!$this->booted) {
                $this->boot();
            }
            $response = $this->handle($request ?? $this->requestFromGlobals());
        } catch (Throwable $ex) {
            ErrorHandler::setServerErrorCode(500);
            echo ErrorHandler::generateError($ex);
            return;
        }

        (new ResponseEmitter())->emit($response);
    }

    public function shutdown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    // endregion

    // region Services

    public function paths(): Paths
    {
        return $this->paths;
    }

    /**
     * @return list<Module>
     */
    public function getModules(): array
    {
        $this->assertBooted();
        return $this->modules;
    }

    /**
     * Explicit escape hatch for tests and integration: reach into the
     * container directly instead of growing App shortcuts per service.
     */
    public function getContainer(): Container
    {
        $this->assertBooted();
        assert($this->container !== null);
        return $this->container;
    }

    public function getKernel(): Kernel
    {
        $this->assertBooted();
        assert($this->kernel !== null);
        return $this->kernel;
    }

    /**
     * @return array{name?:string,autoload?:array{psr-4?:array<string,string>}}
     */
    public function getComposerInfo(): array
    {
        $filename = $this->paths->base . '/composer.json';
        if (!is_file($filename)) {
            return [];
        }
        // composer.json is decoded as a plain array; callers read well-formed entries only
        /** @var array{name?:string,autoload?:array{psr-4?:array<string,string>}} $data */
        $data = Json::decodeArr(Fs::getFile($filename));
        return $data;
    }

    // endregion

    // region Internals

    /**
     * Modules first (by priority), then the configure hooks, then the
     * framework defaults for whatever is still missing. Locked once built.
     */
    private function buildDefinitions(): Definitions
    {
        $definitions = $this->loadModules();

        $this->hooks->configure($definitions);

        // Register the application itself
        $definitions->set(self::class, $this);

        // Modules can register middlewares through the container
        $definitions->set(MiddlewareRegistry::class, $this->middleware);

        // PSR-17 factories of the installed PSR-7 implementation
        foreach (Psr17Discovery::find() as $interface => $class) {
            if (!$definitions->has($interface)) {
                $definitions->bind($interface, $class);
            }
        }

        // Whatever the app (modules, configure hooks) bound explicitly wins over
        // the framework defaults below. Capture this before they are applied:
        // an explicit PSR-3 logger also receives the Kaly diagnostics.
        $explicitLogger = $definitions->has(LoggerInterface::class);

        // Our default implementations if none are provided
        foreach (self::DEFAULT_IMPLEMENTATIONS as $interface => $className) {
            if (!$definitions->has($interface)) {
                $definitions->bind($interface, $className);
            }
        }

        // One CookiePolicy per App: the historical baseline unless the
        // application bound its own. Never a mutable process-global.
        if (!$definitions->has(CookiePolicy::class)) {
            $definitions->set(CookiePolicy::class, CookiePolicy::baseline());
        }

        // The default exception handler explains failures in debug mode only
        if (!array_key_exists('debug', $definitions->parametersFor(ExceptionHandler::class))) {
            $definitions->parameter(ExceptionHandler::class, 'debug', $this->debug);
        }

        // The public directory is a scalar, so FileServer::class resolves out
        // of the box while the PSR factories it needs stay autowired.
        if (!array_key_exists('publicDir', $definitions->parametersFor(FileServer::class))) {
            $definitions->parameter(FileServer::class, 'publicDir', $this->paths->publicDir());
        }

        // Asset sources: the application `assets/` dir under `app`, plus
        // every module that has one. `app` always exists conceptually, even
        // when the directory is missing (sources are only read by the
        // publisher and the dev server, never by url()).
        if (!$definitions->has(AssetSources::class)) {
            $sources = ['app' => $this->paths->assets()];
            foreach ($this->modules as $module) {
                if ($module->hasAssets()) {
                    $sources[$module->getId()] = $module->getAssetsDir();
                }
            }
            $definitions->set(AssetSources::class, new AssetSources($sources));
        }

        // Assets resolves out of the box: dev mode follows debug (dev urls
        // on /_assets), the production version falls back to
        // APP_ASSETS_VERSION then public/assets/.version, lazily.
        if (!array_key_exists('publicDir', $definitions->parametersFor(Assets::class))) {
            $definitions->parameter(Assets::class, 'publicDir', $this->paths->publicDir());
        }
        if (!array_key_exists('dev', $definitions->parametersFor(Assets::class))) {
            $definitions->parameter(Assets::class, 'dev', $this->debug);
        }
        if (!array_key_exists('publicDir', $definitions->parametersFor(AssetPublisher::class))) {
            $definitions->parameter(AssetPublisher::class, 'publicDir', $this->paths->publicDir());
        }

        // A debug logger (null logger if debug is disabled) if none is provided.
        // When the application configured its own PSR-3 logger, the Kaly
        // diagnostics (pipeline trace, ...) follow it instead of staying in
        // the debug.log fallback.
        if (!$definitions->has(self::DEBUG_LOGGER)) {
            if ($explicitLogger) {
                $definitions->set(self::DEBUG_LOGGER, static fn(ContainerInterface $container) => $container->get(LoggerInterface::class));
            } else {
                $definitions->set(self::DEBUG_LOGGER, $this->debug ? new FileLogger($this->paths->base . '/debug.log') : NullLogger::class);
            }
        }

        // Enable translation cache for prod
        if (!$this->debug) {
            $definitions->callback(Translator::class, function (Translator $translator): void {
                $translator->setCacheDir($this->paths->tempFor(Translator::class));
            });
        }

        // The router is made of the modules: each one resolves its own urls
        if (!$definitions->has(RouterInterface::class)) {
            $modules = $this->modules;
            $locales = $this->locales;
            $definitions->set(
                RouterInterface::class,
                static fn(ContainerInterface $container): Router => new Router($modules, $container, $locales),
            );
        }
        if (!$definitions->has(LocaleResolver::class) && $this->locales !== []) {
            $definitions->set(LocaleResolver::class, new LocaleResolver($this->locales[0], $this->locales));
        }

        return $definitions->lock();
    }

    /**
     * Load all modules of the modules folder
     */
    private function loadModules(): Definitions
    {
        $psr4 = $this->getComposerInfo()['autoload']['psr-4'] ?? [];
        $psr4Paths = array_flip($psr4);

        $modules = [];
        $order = 0;
        foreach (Module::findModulesInDir($this->paths->modules()) as $file) {
            $module = Module::fromConfig($file);

            // If not configured in composer, autoload files in module
            $srcDir = $module->getSrcDir();
            $relativeDir = Fs::dir(Fs::relativePath($this->paths->base, $srcDir));
            if (!isset($psr4Paths[$relativeDir]) && is_dir($srcDir)) {
                $module->autoloadFiles();
            }

            $module->loadConfig();

            // Without an explicit priority, the sorted discovery order
            // (100, 200...) keeps the configuration deterministic
            $order += 100;
            if ($module->getPriority() === null) {
                $module->priority($order);
            }

            $modules[] = $module;
        }

        // Lower priorities are configured first, stable on discovery order
        usort($modules, static fn(Module $a, Module $b): int => $a->getPriority() <=> $b->getPriority());
        $this->modules = $modules;

        $definitions = new Definitions();
        foreach ($modules as $module) {
            $definitions->merge($module->definitions());
        }

        // Second pass to allow conditional features across modules
        foreach ($modules as $module) {
            foreach ($module->getWhenAllLoaded() as $callback) {
                $callback($definitions);
            }
        }

        // The declarative phase is over: routing and DI are built from here
        // on, so the modules become a read model.
        foreach ($modules as $module) {
            $module->freeze();
        }

        return $definitions;
    }

    /**
     * Build the request pipeline. The order is fixed by the framework, only
     * the content of the middleware phases is configurable:
     *
     * ```text
     * incoming -> routing -> routed -> route middlewares -> dispatcher -> (kernel) -> outgoing
     * ```
     *
     * A custom RequestHandlerInterface binding takes precedence.
     */
    private function createRequestHandler(): RequestHandlerInterface
    {
        $container = $this->getContainer();

        if ($container->has(RequestHandlerInterface::class)) {
            return $container->get(RequestHandlerInterface::class);
        }

        // Declared route middlewares always run, right before the controller
        $dispatcher = new RouteMiddlewareRunner($container->get(RequestDispatcher::class), $container);

        // The route is known from here on
        $routed = new MiddlewareRunner($dispatcher, $container, $this->middleware, MiddlewareBand::Routed);

        // Fixed structural step of the framework, not a configurable middleware
        $routing = new RoutingHandler($container->get(RouterInterface::class), $container->get(LocaleResolver::class), $routed);

        return new MiddlewareRunner($routing, $container, $this->middleware, MiddlewareBand::Incoming);
    }

    private function requestFromGlobals(): ServerRequestInterface
    {
        $container = $this->getContainer();
        if (!$container->has(ServerRequestFactoryInterface::class) && Psr17Discovery::find() === []) {
            throw new LogicException('No PSR-7 implementation found: run `composer require nyholm/psr7` (or bind the PSR-17 factories)');
        }

        return (new ServerRequestFromGlobals(
            $container->get(ServerRequestFactoryInterface::class),
            $container->get(UriFactoryInterface::class),
            $container->get(UploadedFileFactoryInterface::class),
            $container->get(StreamFactoryInterface::class),
        ))->create();
    }

    /**
     * Log the effective pipeline of the cycle in debug mode.
     *
     * The registry is live — middlewares can be registered before or after
     * boot — so the configured bands are read per request. Showing the
     * executed trace next to the configuration exposes a middleware that was
     * registered but never ran (eg: short-circuited upstream).
     */
    private function logPipeline(HttpContext $ctx): void
    {
        try {
            $configured = $this->middleware->toArray();
            /** @var LoggerInterface $logger */
            $logger = $this->getContainer()->get(self::DEBUG_LOGGER);
            $logger->debug('pipeline status={status} configured incoming={incoming} routed={routed} outgoing={outgoing} | executed={executed}', [
                'status' => (string) $ctx->response()->getStatusCode(),
                'incoming' => self::middlewareNames($configured['incoming'] ?? []),
                'routed' => self::middlewareNames($configured['routed'] ?? []),
                'outgoing' => self::middlewareNames($configured['outgoing'] ?? []),
                'executed' => $ctx->middlewares(),
            ]);
        } catch (Throwable) {
            // The debug dump must never interfere with the cycle
            // @mago-expect lint:no-empty-catch-clause
        }
    }

    /**
     * @param array<array-key, class-string|object> $entries
     * @return list<string>
     */
    private static function middlewareNames(array $entries): array
    {
        $names = [];
        foreach ($entries as $entry) {
            $names[] = is_string($entry) ? $entry : $entry::class;
        }
        return $names;
    }

    private function assertBooted(): void
    {
        if (!$this->booted) {
            throw new LogicException('App must be booted first');
        }
    }

    private function assertNotBooted(string $what): void
    {
        if ($this->booted) {
            throw new LogicException("Cannot change the {$what} of a booted app");
        }
    }

    // endregion
}
