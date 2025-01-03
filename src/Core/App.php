<?php

declare(strict_types=1);

namespace Kaly\Core;

use Throwable;
use Kaly\Util\Fs;
use Kaly\Util\Env;
use Kaly\Util\Json;
use Kaly\Di\Injector;
use Kaly\View\Engine;
use Kaly\Di\Container;
use Kaly\Http\Session;
use Psr\Log\NullLogger;
use Kaly\Di\Definitions;
use Kaly\Log\FileLogger;
use Kaly\Text\Translator;
use Kaly\Http\HttpFactory;
use Kaly\Clock\SystemClock;
use Kaly\Http\ServerRequest;
use Kaly\Router\ClassRouter;
use Psr\Log\LoggerInterface;
use Psr\Clock\ClockInterface;
use Kaly\Http\ResponseEmitter;
use Kaly\Router\RouterInterface;
use Kaly\Router\RequestDispatcher;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Kaly\Http\ResponseProviderInterface;
use Kaly\Router\FaviconProviderInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Kaly\View\EngineInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Kaly\Middleware\MiddlewareRunner;

/**
 * A basic app that should be created from the entry file
 */
class App implements RequestHandlerInterface
{
    use SystemDirectories;
    use HasCallbacks;
    use HasDebug;
    use HasCache;

    // Named entries in di
    public const DEBUG_LOGGER = "debugLogger";
    public const APP_CACHE = "appCache";
    // Env params
    public const IGNORE_DOT_ENV = "IGNORE_DOT_ENV";
    public const ENV_DEBUG = "APP_DEBUG";
    public const ENV_TIMEZONE = "APP_TIMEZONE";
    public const ENV_IDE_PLACEHOLDER = "DUMP_IDE_PLACEHOLDER";
    // Callbacks
    public const CB_BOOTED = "booted";
    public const CB_ERROR = "error";
    public const CB_BEFORE_DEFINTITIONS = "beforeDefinitions";
    public const CB_AFTER_DEFINITIONS = "afterDefinitions";
    public const CB_BEFORE_REQUEST = "beforeRequest";
    public const CB_AFTER_REQUEST = "afterRequest";
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
        // PSR-15
        RequestHandlerInterface::class => MiddlewareRunner::class,
        // PSR-7
        RequestFactoryInterface::class => Psr17Factory::class,
        ResponseFactoryInterface::class => Psr17Factory::class,
        ServerRequestFactoryInterface::class => Psr17Factory::class,
        StreamFactoryInterface::class => Psr17Factory::class,
        UploadedFileFactoryInterface::class => Psr17Factory::class,
        UriFactoryInterface::class => Psr17Factory::class,
        // PSR-3
        LoggerInterface::class => NullLogger::class,
        // Our interfaces
        EngineInterface::class => Engine::class,
        FaviconProviderInterface::class => SiteConfig::class,
        RouterInterface::class => ClassRouter::class,
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
    protected ?Engine $viewEngine = null;
    protected static App $instance;

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
        assert($this->setDebug()); // set debug mode=true if assert are enabled
        assert(is_dir($dir));

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

        // Initialize our services
        Session::configureDefaults($this->getTempDirFor(Session::class));
    }

    /**
     * Load all modules in the modules folder
     * @return Definitions the definitions provided by the modules config files
     */
    protected function loadModules(): Definitions
    {
        $files = Module::findModulesInDir($this->baseDir . "/" . App::FOLDER_MODULES);
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
        $autoloaded = [];
        foreach ($files as $file) {
            $module = Module::fromConfig($file);
            $modules[] = $module;

            // If not configured in composer, autoload files in module
            $srcDir = $module->getSrcDir();
            $relativeDir = Fs::dir(Fs::relativePath($this->baseDir, $srcDir));
            if (!isset($psr4Paths[$relativeDir]) && is_dir($srcDir)) {
                $autoloaded[] = $module->getName();
                $module->autoloadFiles();
            }

            // Load config file
            $module->loadConfig();

            // If no priority, assign one (100,200...)
            if (!$module->getPriority()) {
                $i += 100;
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

        $definitions = $this->updateDefinitions($definitions);

        return $definitions;
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

        $def->set(static::class, $this);
        // Create an alias if necessary
        if (self::class !== static::class) {
            $def->set(self::class, static::class);
        }

        // Register our default implementations if none are provided through modules
        foreach (self::DEFAULT_IMPLEMENTATIONS as $interface => $className) {
            if ($def->miss($interface)) {
                $def->bind($className, $interface);
            }
        }

        // Register a debug logger (null logger if debug is disabled) if none are provided
        if ($def->miss(self::DEBUG_LOGGER)) {
            if ($this->debug) {
                $def->set(self::DEBUG_LOGGER, new FileLogger($this->baseDir . "/debug.log"));
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
        $container = $this->container;

        // Get app cache from container
        $cache = null;
        if ($container->has(self::APP_CACHE)) {
            $cache = $container->get(self::APP_CACHE);
        } elseif ($container->has(CacheInterface::class)) {
            $cache = $container->get(CacheInterface::class);
        }
        if ($cache) {
            assert($cache instanceof CacheInterface);
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
     * Init app state
     * - load modules from "modules" folder
     * - configure the di container
     *
     * You can start adding middlewares after this
     */
    public function boot(): void
    {
        assert($this->booted === false);
        $this->booted = true;

        ErrorHandler::configureDefaults($this->debug);

        if ($this->debug) {
            $this->setupDirectories();
        }

        $definitions = $this->loadModules();

        $this->container = new Container($definitions);
        $this->injector = new Injector($this->container);

        $this->setServicesFromContainer();
        $this->runCallbacks(self::CB_BOOTED);
    }

    public function respond(string $body, int $code = 200): ResponseInterface
    {
        if ($this->container) {
            $response = $this->container->get(ResponseFactoryInterface::class)->createResponse($code);
            return $response->withBody(
                $this->container->get(StreamFactoryInterface::class)->createStream($body)
            );
        }
        return HttpFactory::createResponse($body, $code);
    }

    /**
     * Handle a request and returns its response
     * This may be called back by middlewares
     */
    public function handle(?ServerRequestInterface $request = null): ResponseInterface
    {
        assert($this->booted === true, "App must be booted first");

        // On first request, add dispatcher if needed
        // If we use middlewares, add our request dispatcher
        if ($this->hasMiddlewareRunner()) {
            $runner = $this->getMiddlewareRunner();
            if (!$runner->has(RequestDispatcher::class)) {
                $runner->push(RequestDispatcher::class);
            }
        }

        if ($request === null) {
            $request = HttpFactory::createRequestFromGlobals();
        }
        $request = ServerRequest::createFromRequest($request);

        $this->runCallbacks(self::CB_BEFORE_REQUEST, $request);

        // Run through the middlewares
        try {
            $handler = $this->getRequestHandler();

            // Reset so that handling multiple request with the same app is not an issue
            if ($handler instanceof MiddlewareRunner) {
                $result = $handler->handleNewRequest($request);
            } else {
                $result = $handler->handle($request);
            }
            return $result;
        } catch (Throwable $ex) {
            if ($ex instanceof ResponseProviderInterface) {
                return $ex->getResponse();
            }

            $this->runCallbacks(self::CB_ERROR, $ex);

            $code = $ex->getCode();
            if (!$code) {
                $code = 500;
            }

            $body = ErrorHandler::generateError($ex, $this->getLogger());
            return $this->respond($body, $code);
        } finally {
            $this->runCallbacks(self::CB_AFTER_REQUEST, $request);
        }
    }

    /**
     * This utility method can be used for index scripts
     * It will send the response
     */
    public function run(?ServerRequestInterface $request = null): void
    {
        if (!$this->booted) {
            $this->boot();
        }

        $response = $this->handle($request);

        $emitter = new ResponseEmitter();
        $emitter->emit($response);
    }

    public function getCache(): ?CacheInterface
    {
        assert($this->booted);
        return $this->cache;
    }

    /**
     * @return array<Module>
     */
    public function getModules(): array
    {
        assert($this->booted);
        return $this->modules;
    }

    public function getContainer(): Container
    {
        assert($this->booted);
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
        assert($this->booted);
        assert($this->injector !== null);
        return $this->injector;
    }

    public function getViewEngine(): Engine
    {
        assert($this->booted);
        if ($this->viewEngine === null) {
            $this->viewEngine = $this->get(Engine::class);
        }
        return $this->viewEngine;
    }

    /**
     * Get the app request handler. By default, this should be the middleware runner
     * @return RequestHandlerInterface|MiddlewareRunner
     */
    public function getRequestHandler(): RequestHandlerInterface
    {
        assert($this->booted);
        if ($this->requestHandler === null) {
            $this->requestHandler = $this->get(RequestHandlerInterface::class);
        }
        return $this->requestHandler;
    }

    /**
     * Get the middleware runner (unless you changed it to something else)
     * @return MiddlewareRunner
     */
    public function getMiddlewareRunner(): MiddlewareRunner
    {
        $handler = $this->getRequestHandler();
        assert($handler instanceof MiddlewareRunner);
        return $handler;
    }

    public function hasMiddlewareRunner(): bool
    {
        return $this->getRequestHandler() instanceof MiddlewareRunner;
    }

    public function getLogger(): LoggerInterface
    {
        $logger = $this->getContainer()->get(LoggerInterface::class);
        return $logger;
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
