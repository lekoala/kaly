<?php

declare(strict_types=1);

namespace Kaly;

use Kaly\Di;
use Exception;
use Kaly\Http;
use RuntimeException;
use Kaly\Router\ClassRouter;
use Kaly\Interfaces\RouterInterface;
use Kaly\Exceptions\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Kaly\Interfaces\ResponseProviderInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * A basic app that should be created from the entry file
 */
class App
{
    public const MODULES_FOLDER = "modules";
    public const DEFAULT_MODULE = "App";
    public const CONTROLLER_SUFFIX = "Controller";

    protected bool $debug;
    protected bool $booted = false;
    protected string $baseDir;
    /**
     * @var string[]
     */
    protected array $modules;
    /**
     * @var array<string, mixed>
     */
    protected array $definitions;

    /**
     * Create a new instance of the application
     * It only need the base directory that contains
     * the system folders
     */
    public function __construct(string $dir)
    {
        $this->baseDir = $dir;
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
        if (empty($_ENV['ignore_dot_env']) && is_file($envFile)) {
            $result = parse_ini_file($envFile);
            if (!$result) {
                die("Failed to parse $envFile");
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
     */
    protected function loadModules(): void
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
        $this->definitions = $definitions;
    }

    /**
     * This should be done before handling each request
     * We store the request as part of the definitions because it could
     * be injected in the controller
     *
     * @param ServerRequestInterface $request
     */
    public function configureDi(ServerRequestInterface $request): Di
    {
        $definitions = $this->definitions;
        // Register the app itself
        $definitions[get_called_class()] = $this;
        // Create an alias if necessary
        if (self::class != get_called_class()) {
            $definitions[self::class] = get_called_class();
        }
        // Register a response factory
        $definitions[ResponseFactoryInterface::class] = Http::class;
        // Register a router if none defined
        if (!isset($definitions[RouterInterface::class])) {
            $definitions[RouterInterface::class] = $this->defineBaseRouter($this->modules);
        }
        // Register the global server request by class and name
        $definitions[ServerRequestInterface::class] = $request;
        $definitions['request'] = ServerRequestInterface::class;
        return new Di($definitions);
    }

    /**
     * @param Di $di
     * @param array<string, mixed> $params
     * @return mixed
     */
    public function dispatch(Di $di, array $params)
    {
        $class = $params['controller'] ?? '';
        if (!$class) {
            throw new RuntimeException("Route parameters must include a 'controller' key");
        }
        $inst = $di->get($class);
        $action = $params['action'] ?? '__invoke';
        $arguments = (array)$params['params'] ?? [];
        /** @var callable $callable  */
        $callable = [$inst, $action];
        return call_user_func_array($callable, $arguments);
    }

    /**
     * @param array<string, mixed> $routeParams
     * @param mixed $body
     */
    protected function renderTemplate(Di $di, array $routeParams, $body = null): ?string
    {
        // Check if we have a twig instance
        if (!$this->hasDefinition(\Twig\Loader\LoaderInterface::class)) {
            return null;
        }
        // We only support empty body or a context array
        if ($body && !is_array($body)) {
            return null;
        }
        // We need at least two keys to find a template
        if (!isset($routeParams['controller']) || !isset($routeParams['action'])) {
            return null;
        }

        /** @var \Twig\Environment $twig  */
        $twig = $di->get(\Twig\Environment::class);
        if ($this->debug) {
            $twig->enableDebug();
        }

        // Build view path based on route parameters
        $controllerFolder = mb_strtolower(get_class_name($routeParams['controller']));
        $controllerFolder = mb_substr($controllerFolder, 0, - (strlen(self::CONTROLLER_SUFFIX)));
        $viewName = $controllerFolder . '/' . $routeParams['action'];
        if (isset($routeParams['module']) && $routeParams['module'] !== self::DEFAULT_MODULE) {
            $viewName = '@' . $routeParams['module'] . '/' . $viewName;
        }
        $viewFile = $viewName . ".twig";
        // If we have a view, render with body as context
        if (!$twig->getLoader()->exists($viewFile)) {
            return null;
        }
        $context = $body ? $body : [];
        $body = $twig->render($viewFile, $context);

        return $body;
    }

    public function processRequest(ServerRequestInterface $request, Di $di): ResponseInterface
    {
        $code = 200;
        $body = null;
        $routeParams = [];
        try {
            /** @var RouterInterface $router  */
            $router = $di->get(RouterInterface::class);
            $routeParams = $router->match($request);
            $body = $this->dispatch($di, $routeParams);
        } catch (ResponseProviderInterface $ex) {
            // Will be converted to a response later
            $body = $ex;
        } catch (NotFoundException $ex) {
            $code = $ex->getCode();
            $body = $this->debug ? Util::getExceptionMessageChainAsString($ex) : 'The page could not be found';
        } catch (Exception $ex) {
            $code = 500;
            $body = $this->debug ? Util::getExceptionMessageChainAsString($ex) : 'Server error';
        }

        $json = $request->getHeader('Accept') == Http::CONTENT_TYPE_JSON;
        $forceJson = boolval($request->getQueryParams()['_json'] ?? false);
        $json = $json || $forceJson;

        // We may want to return a template that matches route params if possible
        if (!$json && !empty($routeParams)) {
            $renderedBody = $this->renderTemplate($di, $routeParams, $body);
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
        if ($json && is_array($body)) {
            $response = Http::createJsonResponse($body, $code, $headers);
        } else {
            $response = Http::createHtmlResponse($body, $code, $headers);
        }
        return $response;
    }

    /**
     * You can use this function to add a default router definition
     * to the DI container
     *
     * @param array<string> $modules
     * @return callable
     */
    protected function defineBaseRouter(array $modules): callable
    {
        return function () use ($modules) {
            $classRouter = new ClassRouter();
            $classRouter->setAllowedNamespaces($modules);
            $classRouter->setDefaultNamespace(self::DEFAULT_MODULE);
            $classRouter->setControllerSuffix(self::CONTROLLER_SUFFIX);
            return $classRouter;
        };
    }

    /**
     * Init app state
     * - reads .env files or $_ENV
     * - load modules from "modules" folder
     */
    public function boot(): void
    {
        if ($this->booted) {
            throw new RuntimeException("Already booted");
        }
        $this->loadEnv();
        $this->loadModules();
        $this->booted = true;
    }

    /**
     * Handle a request and returns its response
     */
    public function handle(ServerRequestInterface $request = null): ResponseInterface
    {
        if (!$request) {
            $request = Http::createRequestFromGlobals();
        }

        // We need to configure the di for each request since
        // it can provide the request to the controller
        $di = $this->configureDi($request);
        $response = $this->processRequest($request, $di);
        return $response;
    }

    /**
     * This utility method can be used for index scripts
     * It will send the response
     */
    public function run(ServerRequestInterface $request = null): void
    {
        $this->boot();
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

    /**
     * @return array<string, mixed>
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    public function hasDefinition(string $id): bool
    {
        return isset($this->definitions[$id]);
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
}
