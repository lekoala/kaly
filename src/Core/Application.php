<?php

declare(strict_types=1);

namespace Kaly\Core;

use InvalidArgumentException;
use Kaly\Clock\SystemClock;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Http\ExceptionHandler;
use Kaly\Http\ExceptionHandlerInterface;
use Kaly\Log\FileLogger;
use Kaly\Middleware\MiddlewareBand;
use Kaly\Middleware\MiddlewareRegistry;
use Kaly\Middleware\MiddlewareRunner;
use Kaly\Router\ClassRouter;
use Kaly\Router\RequestDispatcher;
use Kaly\Router\RouterInterface;
use Kaly\Router\RoutingHandler;
use Kaly\Text\LocaleResolver;
use Kaly\Text\Translator;
use Kaly\Text\TranslatorInterface;
use Kaly\Util\Env;
use Kaly\Util\Fs;
use Kaly\Util\Json;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Application of a kaly app
 *
 * The application handles the whole lifecycle (env, modules, container, boot).
 * The request cycle itself is delegated to the kernel.
 */
class Application
{
    use SystemDirectories;
    use HasCallbacks;
    use HasDebug;
    use HasCache;

    // Named entries in di
    public const DEBUG_LOGGER = 'debugLogger';
    public const APP_CACHE = 'appCache';
    // Env params
    public const IGNORE_DOT_ENV = 'IGNORE_DOT_ENV';
    public const ENV_DEBUG = 'APP_DEBUG';
    public const ENV_TIMEZONE = 'APP_TIMEZONE';
    public const ENV_IDE_PLACEHOLDER = 'DUMP_IDE_PLACEHOLDER';
    // Callbacks
    public const CB_BOOTED = 'booted';
    public const CB_ERROR = 'error';
    public const CB_BEFORE_DEFINTITIONS = 'beforeDefinitions';
    public const CB_AFTER_DEFINITIONS = 'afterDefinitions';
    public const CB_BEFORE_REQUEST = 'beforeRequest';
    public const CB_AFTER_REQUEST = 'afterRequest';
    public const AVAILABLE_CALLBACKS = [
        'booted',
        'beforeDefinitions',
        'afterDefinitions',
        'beforeRequest',
        'afterRequest',
        'error',
    ];

    protected const DEFAULT_IMPLEMENTATIONS = [
        // PSR-20
        ClockInterface::class => SystemClock::class,
        // PSR-3
        LoggerInterface::class => NullLogger::class,
        // Our interfaces
        RouterInterface::class => ClassRouter::class,
        ExceptionHandlerInterface::class => ExceptionHandler::class,
        TranslatorInterface::class => Translator::class,
    ];

    protected bool $debug = false;
    protected bool $booted = false;
    /**
     * @var Module[]
     */
    protected array $modules;
    protected ?Container $container = null;
    protected ?Injector $injector = null;
    protected ?RequestHandlerInterface $requestHandler = null;
    protected ?Kernel $kernel = null;
    protected ?MiddlewareRegistry $middlewareRegistry = null;
    protected static Application $instance;

    /**
     * Create a new instance of the application
     *
     * It only need the base directory that contains the system folders
     *
     * It will look for a .env file in the base directory except
     * if the IGNORE_DOT_ENV env flag is set
     */
    final public function __construct(string $dir, bool $loadEnv = true)
    {
        if (!is_dir($dir)) {
            throw new InvalidArgumentException("Base directory '{$dir}' does not exist");
        }

        $this->baseDir = Fs::dir($dir);

        if ($loadEnv && !Env::getBool(self::IGNORE_DOT_ENV)) {
            $this->loadEnv();
        }

        $this->configure();

        self::$instance = $this;
    }

    public static function inst(): self
    {
        return self::$instance;
    }

    /**
     * Load environment variables from .env file
     */
    protected function loadEnv(): void
    {
        $envFile = $this->baseDir . '/.env';
        if (is_file($envFile)) {
            Env::load($envFile);
        }
    }

    protected function configure(): void
    {
        // Initialize our app variables based on env conventions
        if (Env::has(self::ENV_DEBUG)) {
            $this->debug = Env::getBool(self::ENV_DEBUG);
        }
        date_default_timezone_set(Env::getString(self::ENV_TIMEZONE, 'UTC'));
    }

    /**
     * Load all modules in the modules folder
     * @return Definitions the definitions provided by the modules config files
     */
    protected function loadModules(): Definitions
    {
        $files = Module::findModulesInDir($this->baseDir . '/' . self::FOLDER_MODULES);
        $modules = [];
        $definitions = new Definitions();

        $composerInfos = $this->getComposerInfo();
        $psr4Infos = $composerInfos['autoload']['psr-4'] ?? [];
        assert(is_array($psr4Infos));
        $psr4Paths = array_flip($psr4Infos);

        // Modules with a config field can build definitions in it
        // They are basically executed in order unless a custom priority is set
        $i = 0;
        $priorities = [];
        foreach ($files as $file) {
            $module = Module::fromConfig($file);
            $modules[] = $module;

            // If not configured in composer, autoload files in module
            $srcDir = $module->getSrcDir();
            $relativeDir = Fs::dir(Fs::relativePath($this->baseDir, $srcDir));
            if (!isset($psr4Paths[$relativeDir]) && is_dir($srcDir)) {
                $module->autoloadFiles();
            }

            // Load config file
            $module->loadConfig();

            // If no priority, assign one (100,200...) based on the sorted
            // discovery order so that configuration is deterministic.
            // An explicit priority set by the module always wins.
            $i += 100;
            if (!$module->getPriority()) {
                $module->setPriority($i);
            }

            $priorities[$module->getName()] = $module->getPriority();
        }

        // Lower priorities are executed first
        asort($priorities);

        // Create our global definitions
        $definitions = new Definitions();

        foreach ($priorities as $name => $priority) {
            foreach ($modules as $module) {
                if ($module->getName() != $name) {
                    continue;
                }
                $definitions->merge($module->definitions());
            }
        }

        // Execute a second pass to allow conditional features across modules
        foreach ($priorities as $name => $priority) {
            foreach ($modules as $module) {
                if ($module->getName() != $name) {
                    continue;
                }

                $cb = $module->getDefinitionsCallback();
                if ($cb) {
                    $cb($definitions);
                }
            }
        }

        $this->modules = $modules;

        return $this->updateDefinitions($definitions);
    }

    /**
     * @return array{name?:string,autoload?:array{psr-4?:array<string,string>}}
     */
    public function getComposerInfo(): array
    {
        $filename = $this->baseDir . '/composer.json';
        if (!is_file($filename)) {
            return [];
        }
        //@phpstan-ignore-next-line
        return Json::decodeArr(Fs::getFile($filename));
    }

    /**
     * The service container is only configured once on app load.
     * @param Definitions $def Global definitions for the container. Locked once loaded.
     */
    public function updateDefinitions(Definitions $def): Definitions
    {
        $this->runCallbacks(self::CB_BEFORE_DEFINTITIONS, $def);

        // Register the application under its concrete class and its known aliases
        $classes = [static::class, self::class, App::class];
        foreach (array_unique($classes) as $class) {
            $def->set($class, $this);
        }

        // Modules can register middlewares through the container
        $def->set(MiddlewareRegistry::class, $this->middleware());

        // Register our default implementations if none are provided through modules
        foreach (self::DEFAULT_IMPLEMENTATIONS as $interface => $className) {
            if ($def->has($interface)) {
                continue;
            }

            $def->bind($interface, $className);
        }

        // Register a debug logger (null logger if debug is disabled) if none are provided
        if (!$def->has(self::DEBUG_LOGGER)) {
            if ($this->debug) {
                $def->set(self::DEBUG_LOGGER, new FileLogger($this->baseDir . '/debug.log'));
            } else {
                $def->set(self::DEBUG_LOGGER, NullLogger::class);
            }
        }

        // Enable translation cache for prod
        if (!$this->debug) {
            $def->callback(Translator::class, function (Translator $translator): void {
                $translator->setCacheDir($this->getTempDirFor(Translator::class));
            });
        }

        $this->runCallbacks(self::CB_AFTER_DEFINITIONS, $def);

        $def->lock();

        return $def;
    }

    protected function setServicesFromContainer(): void
    {
        assert($this->container !== null);
        $container = $this->container;

        // Get app cache from container
        $cache = null;
        if ($container->has(self::APP_CACHE)) {
            $cache = $container->get(self::APP_CACHE);
        } elseif ($container->has(CacheInterface::class)) {
            $cache = $container->get(CacheInterface::class);
        }
        if ($cache instanceof CacheInterface) {
            $this->cache = $cache;
        }
    }

    protected function isValidCallbackId(string $id): bool
    {
        return in_array($id, self::AVAILABLE_CALLBACKS);
    }

    public function shutdown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    /**
     * Guard against using the app before boot
     */
    protected function assertBooted(): void
    {
        if (!$this->booted) {
            throw new LogicException('App must be booted first');
        }
    }

    /**
     * Init app state
     * - load modules from "modules" folder
     * - configure the di container
     * - build the request kernel
     *
     * You can start adding middlewares after this
     */
    public function boot(): void
    {
        if ($this->booted) {
            throw new LogicException('App is already booted');
        }
        $this->booted = true;

        ErrorHandler::configureDefaults($this->debug);

        if ($this->debug) {
            $this->setupDirectories();
        }

        $definitions = $this->loadModules();

        $this->container = new Container($definitions);
        $this->injector = new Injector($this->container);

        $this->requestHandler = $this->createRequestHandler();
        $this->kernel = new Kernel(
            $this->requestHandler,
            $this->container->get(ExceptionHandlerInterface::class),
            $this->runCallbacks(...),
        );

        $this->setServicesFromContainer();
        $this->runCallbacks(self::CB_BOOTED);
    }

    /**
     * The middleware configuration. It can be used before or after boot:
     *
     * ```php
     * $app->middleware()
     *     ->incoming(TrustedProxy::class)
     *     ->routed(AuthMiddleware::class, priority: 100);
     * ```
     */
    public function middleware(): MiddlewareRegistry
    {
        if ($this->middlewareRegistry === null) {
            $this->middlewareRegistry = new MiddlewareRegistry();
        }
        return $this->middlewareRegistry;
    }

    /**
     * Build the request pipeline. The order is fixed by the framework, only
     * the content of the two middleware bands is configurable:
     *
     * ```text
     * incoming -> routing -> routed -> dispatcher
     * ```
     *
     * A custom RequestHandlerInterface binding takes precedence.
     */
    protected function createRequestHandler(): RequestHandlerInterface
    {
        assert($this->container !== null);

        if ($this->container->has(RequestHandlerInterface::class)) {
            return $this->container->get(RequestHandlerInterface::class);
        }

        $registry = $this->middleware();

        // The route is known from here on
        $routed = new MiddlewareRunner(
            $this->container->get(RequestDispatcher::class),
            $this->container,
            $registry,
            MiddlewareBand::Routed,
        );

        // Fixed structural step of the framework, not a configurable middleware
        $routing = new RoutingHandler($this->container->get(RouterInterface::class), $this->container->get(LocaleResolver::class), $routed);

        return new MiddlewareRunner($routing, $this->container, $registry, MiddlewareBand::Incoming);
    }

    /**
     * Create a simple response using the PSR-17 factories of the container
     */
    public function respond(string $body, int $code = 200): ResponseInterface
    {
        $this->assertBooted();
        $response = $this->getContainer()->get(ResponseFactoryInterface::class)->createResponse($code);
        return $response->withBody($this->getContainer()->get(StreamFactoryInterface::class)->createStream($body));
    }

    public function getKernel(): Kernel
    {
        $this->assertBooted();
        assert($this->kernel !== null);
        return $this->kernel;
    }

    public function getCache(): ?CacheInterface
    {
        $this->assertBooted();
        return $this->cache;
    }

    /**
     * @return array<Module>
     */
    public function getModules(): array
    {
        $this->assertBooted();
        return $this->modules;
    }

    public function getContainer(): Container
    {
        $this->assertBooted();
        assert($this->container !== null);
        return $this->container;
    }

    /**
     * This is a shortcut to access services from the container
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class)
    {
        return $this->getContainer()->get($class);
    }

    public function getInjector(): Injector
    {
        $this->assertBooted();
        assert($this->injector !== null);
        return $this->injector;
    }

    /**
     * Get the app request handler. It is built once during boot.
     */
    public function getRequestHandler(): RequestHandlerInterface
    {
        $this->assertBooted();
        assert($this->requestHandler !== null);
        return $this->requestHandler;
    }

    /**
     * Get the outer middleware runner, the one executing the incoming band
     * (unless you changed the request handler to something else).
     *
     * Prefer middleware() to configure the pipeline.
     */
    public function getMiddlewareRunner(): MiddlewareRunner
    {
        $handler = $this->getRequestHandler();
        if (!$handler instanceof MiddlewareRunner) {
            throw new LogicException('The request handler is not a middleware runner');
        }
        return $handler;
    }

    public function hasMiddlewareRunner(): bool
    {
        return $this->getRequestHandler() instanceof MiddlewareRunner;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->getContainer()->get(LoggerInterface::class);
    }

    public function getDebugLogger(): LoggerInterface
    {
        /** @var LoggerInterface $logger */
        $logger = $this->getContainer()->get(self::DEBUG_LOGGER);
        return $logger;
    }

    public function getBooted(): bool
    {
        return $this->booted;
    }
}
