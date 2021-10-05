<?php

declare(strict_types=1);

namespace Kaly;

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
use Kaly\Interfaces\ResponseProviderInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * A basic app that should be created from the entry file
 */
class App implements RequestHandlerInterface
{
    public const IGNORE_DOT_ENV = "IGNORE_DOT_ENV";
    public const MODULES_FOLDER = "modules";
    public const DEFAULT_MODULE = "App";
    public const CONTROLLER_SUFFIX = "Controller";
    public const DEBUG_LOGGER = "debugLogger";
    public const IP_REQUEST_ATTR = "client-ip";

    protected bool $debug;
    protected bool $booted = false;
    protected bool $hasErrorHandler = false;
    protected string $baseDir;
    /**
     * @var string[]
     */
    protected array $modules;
    protected Di $di;
    protected static ?App $instance = null;
    /**
     * @var array<string|MiddlewareInterface>
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
    final public function __construct(string $dir)
    {
        $this->baseDir = $dir;
        $this->loadEnv();

        self::$instance = $this;
    }

    public static function inst(): self
    {
        if (self::$instance === null) {
            self::$instance = new static(__DIR__);
        }
        return self::$instance;
    }

    /**
     * Load environment variable and init app settings
     * based on them
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
            // Make sure that true/false will be converted to proper bool
            if ($v === 'true') {
                $v = true;
            } elseif ($v === 'false') {
                $v = false;
            }
            $_ENV[$k] = $v;
        }

        // Initialize our app variables based on env conventions
        $this->debug = boolval($_ENV['DEBUG'] ?? false);
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
        // Register the app itself
        $definitions[static::class] = $this;
        // Create an alias if necessary
        if (self::class !== static::class) {
            $definitions[self::class] = static::class;
        }
        // Register a response factory
        $definitions[ResponseFactoryInterface::class] = Http::class;
        // Register a router if none defined
        if (!isset($definitions[RouterInterface::class])) {
            $definitions[RouterInterface::class] = ClassRouter::class;
        }
        // If no logger, register a null logger
        if (!isset($definitions[LoggerInterface::class])) {
            $definitions[LoggerInterface::class] = NullLogger::class;
        }
        // Register a debug logger (null logger if debug is disabled)
        if (!isset($definitions[self::DEBUG_LOGGER])) {
            $definitions[self::DEBUG_LOGGER] = NullLogger::class;
            if ($this->debug) {
                $definitions[self::DEBUG_LOGGER] = new Logger($this->baseDir . "/debug.log");
            }
        }
        // A twig loader has been defined
        // Twig has CoreExtension, EscaperExtension and OptimizerExtension loaded by default
        if (isset($definitions[\Twig\Loader\LoaderInterface::class])) {
            if ($this->debug) {
                // @link https://twig.symfony.com/doc/3.x/functions/dump.html
                $definitions[\Twig\Environment::class . '->'][] = function (\Twig\Environment $twig) {
                    $twig->enableDebug();
                    // Adds dump function to templates
                    $twig->addExtension(new \Twig\Extension\DebugExtension());
                };
            }
            $definitions[\Twig\Environment::class . '->'][] = function (\Twig\Environment $twig) {
                $function = new \Twig\TwigFunction('t', function (string $message, array $parameters = [], string $domain = null) {
                    return t($message, $parameters, $domain);
                });
                $twig->addFunction($function);
                // We define early to make sure they are compiled
                $twig->addGlobal("_state", null);
                $twig->addGlobal("_route", null);
                $twig->addGlobal("_controller", null);
            };
        }
        // Sort definitions as it is much cleaner to debug
        ksort($definitions);

        // Some classes need true definitions, not only being available
        $strictDefinitions = [
            App::class,
            \Twig\Loader\LoaderInterface::class,
        ];
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
        $arguments = (array)$params['params'] ?? [];
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
        // Check if we have a twig instance
        if (!$di->has(\Twig\Loader\LoaderInterface::class)) {
            return null;
        }
        // We only support empty body or a context array
        if ($body && !is_array($body)) {
            return null;
        }
        // We need at least two keys to find a template
        if (empty($routeParams['template'])) {
            return null;
        }

        /** @var \Twig\Environment $twig  */
        $twig = $di->get(\Twig\Environment::class);

        // Set some globals to allow pulling data from our controller or state
        // Defaults globals are _self, _context, _charset
        $twig->addGlobal("_state", $di->get(State::class));
        $twig->addGlobal("_route", $routeParams);
        if (!empty($routeParams['controller'])) {
            $twig->addGlobal("_controller", $di->get($routeParams['controller']));
        }

        // Build view path based on route parameters
        $viewFile = $routeParams['template'];
        if (!str_ends_with($viewFile, '.twig')) {
            $viewFile .= ".twig";
        }
        // If we have a view, render with body as context
        if (!$twig->getLoader()->exists($viewFile)) {
            return null;
        }
        $context = $body ? $body : [];
        $body = $twig->render($viewFile, $context);

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
        $definitions = $this->loadModules();
        $this->di = $this->configureDi($definitions);

        // If there was no error handler registered, register our default handler
        if (!$this->hasErrorHandler) {
            $errorHandler = new ErrorHandler($this);
            array_unshift($this->middlewares, $errorHandler);
        }

        $this->booted = true;
    }

    protected function resolveMiddleware(): ?MiddlewareInterface
    {
        $middleware = current($this->middlewares);
        if (!$middleware) {
            return null;
        }
        if (is_string($middleware)) {
            $middlewareName = (string)$middleware;
            if (!$this->di->has($middlewareName)) {
                throw new RuntimeException("Invalid middleware definition '$middlewareName'");
            }
            /** @var MiddlewareInterface $middleware  */
            $middleware = $this->di->get($middlewareName);
        }
        next($this->middlewares);
        return $middleware;
    }

    protected function processMiddlewares(ServerRequestInterface $request): ?ResponseInterface
    {
        // This will return null once looped over all middlewares
        $middleware = $this->resolveMiddleware();
        if ($middleware) {
            return $middleware->process($request, $this);
        }

        // Reset so that next incoming request will run through all the middlewares
        reset($this->middlewares);

        return null;
    }

    protected function updateRequest(ServerRequestInterface &$request): void
    {
        if (!$request->getAttribute('client-ip')) {
            $request = $request->withAttribute('client-ip', Http::getIp($request));
        }
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

        $response = $this->processMiddlewares($request);
        if ($response) {
            return $response;
        }

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

        $response = $this->prepareResponse($request, $routeParams, $body, $code);
        return $response;
    }

    /**
     * @param array<string, mixed> $routeParams
     * @param mixed $body
     */
    public function prepareResponse(ServerRequestInterface $request, array $routeParams, $body = '', int $code = 200): ResponseInterface
    {
        $acceptHtml = Http::getPreferredContentType($request) == Http::CONTENT_TYPE_HTML;
        $acceptJson = Http::getPreferredContentType($request) == Http::CONTENT_TYPE_JSON;
        $forceJson = boolval($request->getQueryParams()['_json'] ?? false);
        $requestedJson = $acceptJson || $forceJson;

        // We may want to return a template that matches route params if possible
        if ($acceptHtml && empty($routeParams['json']) && !empty($routeParams['template'])) {
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
        if ($requestedJson && !empty($routeParams['json'])) {
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
     * @param string|MiddlewareInterface $middleware
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
     * @param string|MiddlewareInterface $middleware
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
}
