<?php

declare(strict_types=1);

namespace Kaly;

use ErrorException;
use Kaly\Di;
use Kaly\Http;
use RuntimeException;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Kaly\Interfaces\RouterInterface;
use Kaly\Exceptions\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Kaly\Interfaces\FaviconProviderInterface;
use Kaly\Interfaces\ResponseProviderInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * A basic app that should be created from the entry file
 */
class App implements RequestHandlerInterface, MiddlewareInterface
{
    public const MODULES_FOLDER = "modules";
    public const PUBLIC_FOLDER = "public";
    public const TEMP_FOLDER = "temp";
    public const DEFAULT_MODULE = "App";
    public const CONTROLLER_SUFFIX = "Controller";
    public const DEBUG_LOGGER = "debugLogger";
    public const IP_REQUEST_ATTR = "client-ip";
    public const JSON_ROUTE_PARAM = "json";
    public const VIEW_TWIG = "twig";
    public const VIEW_PLATES = "plates";
    public const IGNORE_DOT_ENV = "IGNORE_DOT_ENV";
    public const ENV_DEBUG = "APP_DEBUG";
    public const ENV_TIMEZONE = "APP_TIMEZONE";

    protected const DEFAULT_IMPLEMENTATIONS = [
        FaviconProviderInterface::class => SiteConfig::class,
        ResponseFactoryInterface::class => Http::class,
        LoggerInterface::class => NullLogger::class,
        RouterInterface::class => ClassRouter::class,
    ];

    protected bool $debug;
    protected bool $booted = false;
    protected bool $hasErrorHandler = false;
    protected bool $serveFile = false;
    protected ?string $viewEngine = null;
    protected string $baseDir;
    /**
     * @var string[]
     */
    protected array $modules;
    protected Di $di;
    protected static ?App $instance = null;
    /**
     * @var array<class-string|MiddlewareInterface>
     */
    protected array $middlewares = [];

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
        $this->baseDir = $dir;
        if ($loadEnv) {
            $this->loadEnv();
        }
        $this->configureEnv();

        self::$instance = $this;
    }

    public static function inst(): self
    {
        if (self::$instance === null) {
            self::$instance = new static(__DIR__);
        }
        return self::$instance;
    }

    public function relativePath(string $path): string
    {
        return str_replace($this->baseDir, '', $path);
    }

    public function makeTempFolder(string $folder): string
    {
        $dir = $this->baseDir . '/' . self::TEMP_FOLDER . '/' . $folder;
        if (!is_dir($dir)) {
            mkdir($dir, 0755);
        }
        return $dir;
    }

    /**
     * Load environment variable and init app settings based on them
     */
    protected function loadEnv(): void
    {
        $envFile = $this->baseDir . '/.env';
        $result = [];
        // This can be useful to avoid stat calls
        if (empty($_ENV[self::IGNORE_DOT_ENV]) && is_file($envFile)) {
            $result = parse_ini_file($envFile);
            if (!$result) {
                throw new RuntimeException("Failed to parse $envFile");
            }
        }
        foreach ($result as $k => $v) {
            // Make sure that we are not overwriting variables
            if (isset($_ENV[$k])) {
                throw new RuntimeException("Could not redefine $k in ENV");
            }
            // Convert to proper types
            if ($v === 'true') {
                $v = true;
            } elseif ($v === 'false') {
                $v = false;
            } elseif ($v === 'null') {
                $v = null;
            }
            $_ENV[$k] = $v;
        }
    }

    protected function configureEnv(): void
    {
        // Initialize our app variables based on env conventions
        $this->debug = boolval($_ENV[self::ENV_DEBUG] ?? false);

        // Configure errors
        $level = $this->debug ? -1 : 0;
        error_reporting($level);
        set_error_handler(function (int $severity, string $message, string $file, int $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        // Dates
        $timezoneId = $_ENV[self::ENV_TIMEZONE] ?? 'UTC';
        date_default_timezone_set($timezoneId);
    }

    /**
     * Load all modules in the modules folder and stores definitions
     * @return array<string, mixed>
     */
    protected function loadModules(): array
    {
        // Modules need a config.php file. This avoids having is_file checks in the loop
        // Don't sort results as it is much faster
        $files = glob($this->baseDir . "/" . self::MODULES_FOLDER . "/*/config.php", GLOB_NOSORT);
        if (!$files) {
            $files = [];
        }
        $modules = [];
        $definitions = [];
        // Modules can return definitions that will be passed to the Di container
        foreach ($files as $file) {
            $modules[] = basename(dirname($file));
            // Avoid leaking local variables from config files
            $includer = function (string $file, array $definitions) {
                $config = require $file;
                if (is_array($config)) {
                    $definitions = array_merge_distinct($definitions, $config);
                }
                return $definitions;
            };
            $definitions = $includer($file, $definitions);
        }
        $this->modules = $modules;
        return $definitions;
    }

    /**
     * The di container is only configured once on app load.
     * For request specific services, use the State class
     * @param array<string, mixed> $definitions
     */
    public function configureDi(array $definitions): Di
    {
        $strictDefinitions = [
            App::class,
        ];
        // Register the app itself
        $definitions[static::class] = $this;
        // Create an alias if necessary
        if (self::class !== static::class) {
            $definitions[self::class] = static::class;
        }

        // Register our default implementations if none are provided
        foreach (self::DEFAULT_IMPLEMENTATIONS as $interface => $className) {
            if (!isset($definitions[$interface])) {
                $definitions[$interface] = $className;
            }
        }

        // Register a debug logger (null logger if debug is disabled)
        if (!isset($definitions[self::DEBUG_LOGGER])) {
            $definitions[self::DEBUG_LOGGER] = NullLogger::class;
            if ($this->debug) {
                $definitions[self::DEBUG_LOGGER] = new Logger($this->baseDir . "/debug.log");
            }
        }

        // Check for a view engine
        if (isset($definitions[\Twig\Loader\LoaderInterface::class])) {
            $strictDefinitions[] = \Twig\Loader\LoaderInterface::class;
            ViewBridge::configureTwig($this, $definitions);
            $this->viewEngine = self::VIEW_TWIG;
        } elseif (isset($definitions[\League\Plates\Engine::class])) {
            $strictDefinitions[] = \League\Plates\Engine::class;
            ViewBridge::configurePlates($this, $definitions);
            $this->viewEngine = self::VIEW_PLATES;
        }

        // Sort definitions as it is much cleaner to debug
        ksort($definitions);

        return new Di($definitions, $strictDefinitions);
    }

    /**
     * @param array<string, mixed> $params
     * @return mixed
     */
    public function dispatch(Di $di, ServerRequestInterface $request, array &$params)
    {
        $class = $params['controller'] ?? '';
        if (!$class) {
            throw new RuntimeException("Route parameters must include a 'controller' key");
        }
        $inst = $di->get($class);
        if (method_exists($inst, "updateRouteParameters")) {
            $inst->updateRouteParameters($params);
        }
        $action = $params['action'] ?? '__invoke';
        $arguments = $params['params'] ?? [];
        if (!is_array($arguments)) {
            throw new RuntimeException("Arguments must be a valid array");
        }
        // The request is always the first argument
        array_unshift($arguments, $request);
        return $inst->{$action}(...$arguments);
    }

    /**
     * @param array<string, mixed> $routeParams
     * @param mixed $body
     */
    protected function renderTemplate(Di $di, array $routeParams, $body = null): ?string
    {
        // We only support empty body or a context array
        if ($body && !is_array($body)) {
            return null;
        }
        // We need at least two keys to find a template
        if (empty($routeParams['template'])) {
            return null;
        }

        switch ($this->viewEngine) {
            case self::VIEW_TWIG:
                $body = ViewBridge::renderTwig($di, $routeParams, $body);
                break;
            case self::VIEW_PLATES:
                $body = ViewBridge::renderPlates($di, $routeParams, $body);
                break;
        }

        return $body;
    }

    /**
     * Init app state
     * - load modules from "modules" folder
     * - configure the Di container
     * - add global error handler if none set
     */
    public function boot(): void
    {
        if ($this->booted) {
            throw new RuntimeException("Already booted");
        }

        $tempDir = $this->baseDir . '/' . self::TEMP_FOLDER;
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755);
        }

        $definitions = $this->loadModules();
        $this->di = $this->configureDi($definitions);

        // If there was no error handler registered, register our default handler
        if (!$this->hasErrorHandler) {
            $errorHandler = new ErrorHandler($this);
            array_unshift($this->middlewares, $errorHandler);
        }

        $this->booted = true;
    }

    /**
     * @param class-string|MiddlewareInterface $middleware
     */
    protected function resolveMiddleware($middleware): ?MiddlewareInterface
    {
        if (is_string($middleware)) {
            $middlewareName = (string)$middleware;
            if (!$this->di->has($middlewareName)) {
                throw new RuntimeException("Invalid middleware definition '$middlewareName'");
            }
            /** @var MiddlewareInterface $middleware  */
            $middleware = $this->di->get($middlewareName);
        }
        return $middleware;
    }

    protected function updateRequest(ServerRequestInterface &$request): void
    {
        if (!$request->getAttribute('client-ip')) {
            $request = $request->withAttribute('client-ip', Http::getIp($request));
        }
    }

    protected function serveFile(string $path): ?ResponseInterface
    {
        $filePath = $this->baseDir . '/' . self::PUBLIC_FOLDER . $path;
        if (!is_file($filePath)) {
            return null;
        }
        $contents = file_get_contents($filePath);
        if (!$contents) {
            throw new RuntimeException("Failed to read file");
        }
        $contentType = mime_content_type($filePath);
        if (!$contentType) {
            $contentType = Http::CONTENT_TYPE_STREAM;
        }
        return Http::respond($contents, 200, [
            "Content-type" => $contentType
        ]);
    }

    protected function serveFavicon(): ResponseInterface
    {
        /** @var FaviconProviderInterface $provider  */
        $provider = $this->di->get(FaviconProviderInterface::class);
        return Http::respond($provider->getSvgIcon(), 200, [
            'Content-type' => 'image/svg+xml'
        ]);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->updateRequest($request);

        $code = 200;
        $body = null;
        $routeParams = [];

        /** @var State $state */
        $state = $this->di->get(State::class);

        /** @var Translator $translator  */
        $translator = $this->di->get(Translator::class);
        $state->setTranslator($translator);
        $state->setRequest($request);
        $state->setLocaleFromRequest();
        try {
            /** @var RouterInterface $router  */
            $router = $this->di->get(RouterInterface::class);
            $routeParams = $router->match($request);
            if (!empty($routeParams['locale'])) {
                $state->setLocale($routeParams['locale']);
            }
            $body = $this->dispatch($this->di, $request, $routeParams);
        } catch (ResponseProviderInterface $ex) {
            // Will be converted to a response later
            $body = $ex;
        } catch (NotFoundException $ex) {
            $code = $ex->getCode();
            $body = $this->debug ? $ex->getMessage() : 'The page could not be found';
        }

        return $this->prepareResponse($request, $routeParams, $body, $code);
    }

    /**
     * Handle a request and returns its response
     * This may be called back by middlewares
     */
    public function handle(ServerRequestInterface $request = null): ResponseInterface
    {
        if (!$request) {
            $request = Http::createRequestFromGlobals();
        }

        // Serve public files... this should really be handled by your webserver instead
        if ($this->serveFile) {
            $fileResponse = $this->serveFile($request->getUri()->getPath());
            if ($fileResponse) {
                return $fileResponse;
            }
        }

        // Prevent generic favicon.ico requests to go through
        if ($request->getUri()->getPath() === "/favicon.ico") {
            return $this->serveFavicon();
        }

        // Keep this into handle function to avoid spamming the stack with method calls
        $middleware = current($this->middlewares);
        next($this->middlewares);

        if ($middleware) {
            $middleware = $this->resolveMiddleware($middleware);
            if ($middleware) {
                return $middleware->process($request, $this);
            }
        }

        // Reset so that next incoming request will run through all the middlewares
        reset($this->middlewares);

        return $this->process($request, $this);
    }

    /**
     * @param array<string, mixed> $routeParams
     * @param mixed $body
     */
    public function prepareResponse(ServerRequestInterface $request, array $routeParams, $body = '', int $code = 200): ResponseInterface
    {
        $preferredType = Http::getPreferredContentType($request);
        $acceptHtml = $preferredType == Http::CONTENT_TYPE_HTML;
        $acceptJson = $preferredType == Http::CONTENT_TYPE_JSON;
        $forceJson = boolval($request->getQueryParams()['_json'] ?? false);
        $requestedJson = $acceptJson || $forceJson;

        // We may want to return a template that matches route params if possible
        if ($acceptHtml && empty($routeParams[self::JSON_ROUTE_PARAM]) && !empty($routeParams['template'])) {
            $renderedBody = $this->renderTemplate($this->di, $routeParams, $body);
            if ($renderedBody) {
                $body = $renderedBody;
            }
        }

        if ($body) {
            // We have a response provider
            if ($body instanceof ResponseProviderInterface) {
                $body = $body->getResponse();
            }

            // We have a response, return early
            if ($body instanceof ResponseInterface) {
                return $body;
            }
        }

        // We don't have a suitable response, transform body
        $headers = [];

        // We want and can return a json response
        if ($requestedJson && !empty($routeParams[self::JSON_ROUTE_PARAM])) {
            $response = Http::createJsonResponse($body, $code, $headers);
        } else {
            $response = Http::createHtmlResponse($body, $code, $headers);
        }
        return $response;
    }

    /**
     * This utility method can be used for index scripts
     * It will send the response
     */
    public function run(ServerRequestInterface $request = null): void
    {
        if (!$this->booted) {
            $this->boot();
        }
        $response = $this->handle($request);
        Http::sendResponse($response);
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    /**
     * @return array<string>
     */
    public function getModules(): array
    {
        return $this->modules;
    }

    public function getDi(): Di
    {
        return $this->di;
    }

    public function getDebug(): bool
    {
        return $this->debug;
    }

    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    /**
     * @param class-string|MiddlewareInterface $middleware
     */
    public function addMiddleware($middleware, bool $debugOnly = false): bool
    {
        if ($this->booted) {
            throw new RuntimeException("Cannot add middlewares once booted");
        }
        if ($debugOnly && !$this->debug) {
            return false;
        }
        $this->middlewares[] = $middleware;
        return true;
    }

    /**
     * This should probably be called before any other middleware
     * @param class-string|MiddlewareInterface $middleware
     */
    public function addErrorHandler($middleware, bool $debugOnly = false): bool
    {
        if ($this->hasErrorHandler) {
            throw new RuntimeException("Error handler already set");
        }
        $result = $this->addMiddleware($middleware, $debugOnly);
        if ($result) {
            $this->hasErrorHandler = true;
        }
        return $result;
    }

    public function getServeFile(): bool
    {
        return $this->serveFile;
    }

    public function setServeFile(bool $serveFile): self
    {
        $this->serveFile = $serveFile;
        return $this;
    }
}
