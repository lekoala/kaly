<?php

declare(strict_types=1);

namespace Kaly;

use Closure;
use Kaly\Di;
use Exception;
use Kaly\Http;
use Throwable;
use ErrorException;
use RuntimeException;
use Psr\Log\NullLogger;
use ReflectionFunction;
use ReflectionNamedType;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use JsonSerializable;
use Kaly\Interfaces\RouterInterface;
use Kaly\Exceptions\NotFoundException;
use Kaly\Interfaces\JsonRouteInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Kaly\Interfaces\FaviconProviderInterface;
use Kaly\Interfaces\ResponseProviderInterface;
use Kaly\Interfaces\TemplateProviderInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * A basic app that should be created from the entry file
 */
class App implements RequestHandlerInterface, MiddlewareInterface
{
    // Folders
    public const FOLDER_MODULES = "modules";
    public const FOLDER_PUBLIC = "public";
    public const FOLDER_TEMP = "temp";
    public const FOLDER_RESOURCES = "resources";
    public const FOLDER_SESSIONS = "sessions";
    // Router
    public const DEFAULT_MODULE = "App";
    public const DEFAULT_CONTROLLER_SUFFIX = "Controller";
    // Named services
    public const DEBUG_LOGGER = "debugLogger";
    // Request attributes
    public const ATTR_IP_REQUEST = "client-ip";
    public const ATTR_REQUEST_ID_REQUEST = "request-id";
    public const ATTR_ROUTE_REQUEST = "route";
    public const ATTR_LOCALE_REQUEST = "locale";
    // Route params
    public const ROUTE_PARAM_JSON = "json";
    // View engines
    public const VIEW_TWIG = "twig";
    public const VIEW_PLATES = "plates";
    public const VIEW_QIK = "qik";
    // Env params
    public const IGNORE_DOT_ENV = "IGNORE_DOT_ENV";
    public const IGNORE_INI = "IGNORE_INI";
    public const ENV_DEBUG = "APP_DEBUG";
    public const ENV_TIMEZONE = "APP_TIMEZONE";
    // Callbacks
    public const CALLBACK_ERROR = "error";

    protected const DEFAULT_IMPLEMENTATIONS = [
        FaviconProviderInterface::class => SiteConfig::class,
        ResponseFactoryInterface::class => Http::class,
        LoggerInterface::class => NullLogger::class,
        RouterInterface::class => ClassRouter::class,
    ];

    protected bool $debug = false;
    protected bool $booted = false;
    protected bool $serveFile = false;
    protected ?string $viewEngine = null;
    protected string $baseDir;
    /**
     * @var string[]
     */
    protected array $modules;
    protected Di $di;
    protected MiddlewareRunner $middlewareRunner;
    protected ServerRequestInterface $request;
    protected static ?App $instance = null;
    /**
     * @var array<string, Closure[]>
     */
    protected array $callbacks = [];
    /**
     * @var array<string>
     */
    protected array $noCache = [];

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

        $this->middlewareRunner = new MiddlewareRunner($this);

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

    public function makeTemp(string $folder, string $file = null): string
    {
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . self::FOLDER_TEMP . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($dir)) {
            mkdir($dir, 0755);
        }
        if ($file) {
            $dir .= DIRECTORY_SEPARATOR . $file;
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
        // This can be useful to avoid stat calls and if everything is defined on the server
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

        // If your server is configured properly, set IGNORE_INI env variable
        if (empty($_ENV[self::IGNORE_INI])) {
            $this->configureIni();
        }
    }

    protected function configureIni(): void
    {
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_save_path($this->makeTemp(self::FOLDER_SESSIONS));
        // This is required for middlewares/php-session
        ini_set('session.use_trans_sid', '0');
        // Use middleware instead
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '1');
        // Prevent PHP to send headers
        ini_set('session.cache_limiter', '');
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
        $files = glob($this->baseDir . "/" . self::FOLDER_MODULES . "/*/config.php", GLOB_NOSORT);
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
                if ($config && is_array($config)) {
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
     * @param Closure $callable
     * @param array<mixed> $params
     * @return mixed
     */
    public function inject($callable, array $params = [])
    {
        $refl = new ReflectionFunction($callable);
        $reflParameters = $refl->getParameters();
        $args = [];
        $i = 0;
        foreach ($reflParameters as $reflParameter) {
            $type = $reflParameter->getType();
            if (!$type instanceof ReflectionNamedType) {
                throw new Exception("Parameter has no name");
            }
            // Use provided (indexed or named) or resolve using di
            if (isset($params[$i])) {
                $args[] = $params[$i];
            } elseif (isset($params[$type->getName()])) {
                $args[] = $params[$type->getName()];
            } else {
                if (!$this->di->has($type->getName())) {
                    throw new Exception("Parameter {$type->getName()} is not defined in the Di container");
                }
                $args[] = $this->di->get($type->getName());
            }
            $i++;
        }
        $result = $callable(...$args);
        return $result;
    }

    /**
     * The di container is only configured once on app load.
     * @param array<string, mixed> $definitions
     */
    public function configureDi(array $definitions): Di
    {
        $noCache = $this->noCache;
        // Register the app itself
        $definitions[static::class] = $this;
        // Create an alias if necessary
        if (self::class !== static::class) {
            $definitions[self::class] = static::class;
        }

        // Register a static alias for request that should not be cached
        $definitions[ServerRequestInterface::class] = App::class . "::getRequest";
        $noCache[] = ServerRequestInterface::class;

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
            ViewBridge::configureTwig($this, $definitions);
            $this->viewEngine = self::VIEW_TWIG;
        } elseif (isset($definitions[\League\Plates\Engine::class])) {
            ViewBridge::configurePlates($this, $definitions);
            $this->viewEngine = self::VIEW_PLATES;
        } elseif (isset($definitions[\Qiq\Template::class])) {
            ViewBridge::configureQiq($this, $definitions);
            $this->viewEngine = self::VIEW_QIK;
        }

        // Sort definitions as it is much cleaner to debug
        ksort($definitions);

        return new Di($definitions, $noCache);
    }

    /**
     * @param array<string, mixed> $route
     * @return mixed
     */
    public function dispatch(ServerRequestInterface $request, array &$route)
    {
        /** @var string|null $class  */
        $class = $route[RouterInterface::CONTROLLER] ?? '';
        if (!$class) {
            throw new RuntimeException("Route parameters must include a 'controller' key");
        }
        $inst = $this->di->get($class);

        // Check for interfaces
        if ($inst instanceof JsonRouteInterface) {
            $route[self::ROUTE_PARAM_JSON] = true;
        }
        if ($inst instanceof TemplateProviderInterface) {
            $route[RouterInterface::TEMPLATE] = $inst->getTemplate();
        }

        $action = $route[RouterInterface::ACTION] ?? RouterInterface::FALLBACK_ACTION;
        $arguments = $route[RouterInterface::PARAMS] ?? [];
        if (!is_array($arguments)) {
            throw new RuntimeException("Arguments must be a valid array");
        }
        // The request is always the first argument
        array_unshift($arguments, $request);
        // Syntax sugar for handling post
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'])) {
            $arguments[] = $request->getParsedBody();
        }

        $result = $inst->{$action}(...$arguments);
        // Special handling for JsonSerializable
        if (is_object($result) && $result instanceof JsonSerializable) {
            $route[self::ROUTE_PARAM_JSON] = true;
            $result = $result->jsonSerialize();
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $route
     * @param mixed|array<string, mixed> $body
     */
    protected function renderTemplate(array $route, $body = null): ?string
    {
        // We only support empty body or a context array
        if ($body && !is_array($body)) {
            return null;
        }
        // We need a template param
        if (empty($route[RouterInterface::TEMPLATE])) {
            return null;
        }

        /** @var array<string, mixed> $body  */
        if (!$body) {
            $body = [];
        }

        $body = match ($this->viewEngine) {
            self::VIEW_TWIG => ViewBridge::renderTwig($this->di, $route, $body),
            self::VIEW_PLATES => ViewBridge::renderPlates($this->di, $route, $body),
            self::VIEW_QIK => ViewBridge::renderQiq($this->di, $route, $body),
            default => throw new RuntimeException("Cannot render template"),
        };

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

        $tempDir = $this->baseDir . '/' . self::FOLDER_TEMP;
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755);
        }

        $resourcesDir = $this->getResourcesDir();
        if (!is_dir($resourcesDir)) {
            mkdir($resourcesDir, 0755, true);
        }

        $definitions = $this->loadModules();
        $this->di = $this->configureDi($definitions);

        // Enable translation cache for prod
        if (!$this->debug) {
            /** @var Translator $translator  */
            $translator = $this->di->get(Translator::class);
            $translator->setCacheDir($this->makeTemp("translator"));
        }

        $this->booted = true;
    }

    /**
     * Update request with convention based attributes
     *
     * Compatible with common middlewares/* implementation
     */
    protected function updateRequest(ServerRequestInterface &$request): void
    {
        // Add attributes
        if (!$request->getAttribute(self::ATTR_IP_REQUEST)) {
            $request = $request->withAttribute(self::ATTR_IP_REQUEST, Http::getIp($request));
        }
        if (!$request->getAttribute(self::ATTR_REQUEST_ID_REQUEST)) {
            $request = $request->withAttribute(self::ATTR_REQUEST_ID_REQUEST, bin2hex(random_bytes(24)));
        }
        $this->setRequest($request);
    }

    protected function serveFile(string $path): ?ResponseInterface
    {
        $filePath = $this->baseDir . '/' . self::FOLDER_PUBLIC . $path;
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

        /** @var Translator $translator  */
        $translator = $this->di->get(Translator::class);
        $translator->setLocaleFromRequest($request);

        $code = 200;
        $body = null;
        $route = [];

        try {
            /** @var RouterInterface $router  */
            $router = $this->di->get(RouterInterface::class);
            $route = $router->match($request);

            $request = $request->withAttribute(self::ATTR_ROUTE_REQUEST, $route);
            if (!empty($route[RouterInterface::LOCALE])) {
                $request = $request->withAttribute(self::ATTR_LOCALE_REQUEST, $route[RouterInterface::LOCALE]);
                $translator->setCurrentLocale($route[RouterInterface::LOCALE]);
            }
            $this->setRequest($request);

            $body = $this->dispatch($request, $route);
        } catch (NotFoundException $ex) {
            $code = $ex->getIntCode();
            $body = $this->debug ? $ex->getMessage() : 'The page could not be found';
        }

        return $this->prepareResponse($request, $route, $body, $code);
    }

    /**
     * Handle a request and returns its response
     * This may be called back by middlewares
     */
    public function handle(ServerRequestInterface $request = null): ResponseInterface
    {
        if (!$this->booted) {
            throw new RuntimeException("Cannot handle request until booted");
        }

        if (!$request) {
            $request = Http::createRequestFromGlobals();
        }

        $this->request = $request;

        // Serve public files... this should really be handled by your webserver instead
        if ($this->serveFile) {
            $fileResponse = $this->serveFile($request->getUri()->getPath());
            if ($fileResponse) {
                return $fileResponse;
            }
        }
        // Prevent generic favicon.ico requests to go through if not handled by the webserver
        if ($request->getUri()->getPath() === "/favicon.ico") {
            return $this->serveFavicon();
        }
        // Prevent file requests to go through routing
        $basePath = basename($request->getUri()->getPath());
        if (str_contains($basePath, ".")) {
            return Http::respond("File not found", 404);
        }

        try {
            return $this->middlewareRunner->handle($request);
        } catch (Throwable $ex) {
            if ($ex instanceof ResponseProviderInterface) {
                return $ex->getResponse();
            }

            // Log application error if needed
            $this->getLogger()->error($ex->getMessage() . " ({$ex->getFile()}:{$ex->getLine()})");

            $this->runCallbacks(self::class, self::CALLBACK_ERROR, [$ex]);

            $code = 500;
            $body = 'Server error';

            // Make it nice for DX
            if ($this->getDebug()) {
                $line = $ex->getLine();
                $file = $ex->getFile();
                $type = get_class($ex);
                $message = $ex->getMessage();
                $trace = $ex->getTraceAsString();
                if (in_array(real_sapi_name(), ['cli', 'phpdbg'])) {
                    $body = "$type in $file:$line\n---\n$message\n---\n$trace";
                } else {
                    $idePlaceholder = $_ENV['DUMP_IDE_PLACEHOLDER'] ?? 'vscode://file/{file}:{line}:0';
                    $ideLink = str_replace(['{file}', '{line}'], [$file, $line], $idePlaceholder);
                    $body = "<pre><code>$type</code> in <a href=\"$ideLink\">$file:$line</a>";
                    $body .= "<h1>$message</h1>Trace:<br/>$trace</pre>";
                }
            }

            return $this->prepareResponse($request, [], $body, $code);
        }
    }

    /**
     * @param array<string, mixed> $route
     * @param mixed $body
     */
    public function prepareResponse(
        ServerRequestInterface $request,
        array $route,
        $body = '',
        int $code = 200
    ): ResponseInterface {
        $forceJson = boolval($request->getQueryParams()['_json'] ?? false);
        $priorityList = [
            Http::CONTENT_TYPE_HTML
        ];
        if ($forceJson || !empty($route[self::ROUTE_PARAM_JSON])) {
            $priorityList = [
                Http::CONTENT_TYPE_JSON,
                Http::CONTENT_TYPE_HTML,
            ];
        }
        $preferredType = Http::getPreferredContentType($request, $priorityList);
        $acceptHtml = $preferredType == Http::CONTENT_TYPE_HTML;
        $acceptJson = $preferredType == Http::CONTENT_TYPE_JSON;

        $requestedJson = $acceptJson || $forceJson;

        // We may want to return a template that matches route params if possible
        if ($acceptHtml && empty($route[self::ROUTE_PARAM_JSON]) && !empty($route['template'])) {
            $renderedBody = $this->renderTemplate($route, $body);
            if ($renderedBody) {
                $body = $renderedBody;
            }
        }

        if ($body) {
            // We have a response, return early
            if ($body instanceof ResponseInterface) {
                return $body;
            }
        }

        // We don't have a suitable response, transform body
        $headers = [];

        // We want and can return a json response
        if ($requestedJson && !empty($route[self::ROUTE_PARAM_JSON])) {
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

    /**
     * @param class-string $class
     * @param string $type
     * @param Closure $callable
     */
    public function addCallback(string $class, string $type = "default", callable $callable = null): self
    {
        if ($this->booted) {
            throw new RuntimeException("Framework is already booted");
        }
        if (!$callable) {
            throw new InvalidArgumentException("You must pass a callable");
        }
        $key = "$class.$type";
        $this->callbacks[$key][] = $callable;
        return $this;
    }

    /**
     * @param class-string $class
     * @param array<mixed> $params
     */
    public function runCallbacks(string $class, string $type = "default", array $params = []): void
    {
        $key = "$class.$type";
        if (empty($this->callbacks[$key])) {
            return;
        }
        foreach ($this->callbacks[$key] as $callable) {
            $this->inject($callable, $params);
        }
    }

    public function addNoCache(string $id): void
    {
        if ($this->booted) {
            throw new RuntimeException("Framework is already booted");
        }
        $this->noCache[] = $id;
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    public function getPublicDir(): string
    {
        return $this->getBaseDir() . DIRECTORY_SEPARATOR . self::FOLDER_PUBLIC;
    }

    public function getResourcesDir(): string
    {
        return $this->getPublicDir() . DIRECTORY_SEPARATOR . self::FOLDER_RESOURCES;
    }

    public function getResourceDir(string $dir, bool $create = false): string
    {
        $dir = $this->getResourcesDir() . DIRECTORY_SEPARATOR . $dir;
        if ($create && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public function getModulesDir(): string
    {
        return $this->getBaseDir() . DIRECTORY_SEPARATOR . self::FOLDER_MODULES;
    }

    public function getModuleDir(string $module): string
    {
        return $this->getModulesDir() . DIRECTORY_SEPARATOR . $module;
    }

    public function getClientModuleDir(string $module): string
    {
        return $this->getModuleDir($module) . DIRECTORY_SEPARATOR . 'client' . DIRECTORY_SEPARATOR . 'dist';
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

    public function getMiddlewareRunner(): MiddlewareRunner
    {
        return $this->middlewareRunner;
    }

    public function getLogger(): LoggerInterface
    {
        /** @var LoggerInterface $logger  */
        $logger = $this->getDi()->get(LoggerInterface::class);
        return $logger;
    }

    public function getDebugLogger(): LoggerInterface
    {
        /** @var LoggerInterface $logger  */
        $logger = $this->getDi()->get(self::DEBUG_LOGGER);
        return $logger;
    }

    public function &getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function setRequest(ServerRequestInterface &$request): self
    {
        $this->request = $request;
        return $this;
    }

    public function getBooted(): bool
    {
        return $this->booted;
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
