<?php

declare(strict_types=1);

namespace Kaly;

use Exception;
use Stringable;
use RuntimeException;
use Nyholm\Psr7\Response;
use Kaly\Exceptions\RouterException;
use Kaly\Exceptions\RedirectException;
use Psr\Http\Message\ResponseInterface;
use Kaly\Exceptions\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A basic app that should be created from the entry file
 */
class App
{
    protected bool $debug;
    protected string $baseDir;
    /**
     * @var string[]
     */
    protected array $modules;

    public function __construct(string $dir)
    {
        $this->baseDir = $dir;
    }

    protected function loadEnv(): void
    {
        $envFile = $this->baseDir . '/.env';
        $result = [];
        if (empty($_ENV['ignore_dot_env']) && is_file($envFile)) {
            $result = parse_ini_file($envFile);
            if (!$result) {
                die("Failed to parse $envFile");
            }
        }
        foreach ($result as $k => $v) {
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
     * Load all modules in the basedir
     *
     * @return array<string, mixed>
     */
    protected function loadModules(): array
    {
        // Anything with a config.php file is a module
        $files = glob($this->baseDir . "/*/config.php");
        if (!$files) {
            return [];
        }

        $modules = [];
        $definitions = [];
        foreach ($files as $file) {
            $modules[] = basename(dirname($file));
            $config = require $file;
            if (is_array($config)) {
                $definitions = array_merge_recursive($definitions, $config);
            }
        }
        $this->modules = $modules;

        return $definitions;
    }

    /**
     * @param array<string, mixed> $definitions
     * @param ServerRequestInterface $request
     */
    protected function configureDi(array $definitions = [], ServerRequestInterface $request = null): Di
    {
        // Register the app itself
        $definitions[get_called_class()] = $this;
        // Create an alias if necessary
        if (self::class != get_called_class()) {
            $definitions[self::class] = get_called_class();
        }
        // Register the request
        $definitions[ServerRequestInterface::class] = $request;
        $definitions['request'] = ServerRequestInterface::class;
        // Register a router
        if (!isset($definitions[RouterInterface::class])) {
            $definitions[RouterInterface::class] = function () {
                $classRouter = new ClassRouter();
                $classRouter->setAllowedNamespaces($this->modules);
                return $classRouter;
            };
        }
        return new Di($definitions);
    }

    /**
     * @return Response|string|Stringable|array<string, mixed>
     */
    protected function routeRequest(ServerRequestInterface $request, Di $di)
    {
        /** @var RouterInterface $router */
        $router = $di->get(RouterInterface::class);
        $result = $router->match($request, $di);
        return $result;
    }

    public function run(ServerRequestInterface $request = null): void
    {
        if (!$request) {
            $request = Http::createRequestFromGlobals();
        }

        // boot
        $this->loadEnv();
        $definitions = $this->loadModules();
        $di = $this->configureDi($definitions, $request);

        // route request and deal with predefined exceptions
        $code = 200;
        $response = $body = null;
        try {
            $body = $this->routeRequest($request, $di);
        } catch (RedirectException $ex) {
            $response = Http::createRedirectResponse($ex->getUrl(), $ex->getCode(), $ex->getMessage());
        } catch (ValidationException $ex) {
            $code = 403;
            $body = $this->getChainedMessages($ex);
        } catch (RouterException $ex) {
            $code = 404;
            $body = $this->debug ? $this->getChainedMessages($ex) : 'The page could not be found';
        } catch (Exception $ex) {
            $code = 500;
            $body = $this->debug ? $this->getChainedMessages($ex) : 'Server error';
        }

        // We have a response
        if ($body instanceof ResponseInterface) {
            $response = $body;
        }

        // We don't have a suitable response, transform body
        if (!$response) {
            $json = $request->getHeader('Accept') == Http::CONTENT_TYPE_JSON;
            $forceJson = boolval($request->getQueryParams()['_json'] ?? false);
            $json = $json || $forceJson;
            $headers = [];

            if ($json) {
                $response = Http::createJsonResponse($code, $body, $headers);
            } else {
                $response = Http::createHtmlResponse($code, $body, $headers);
            }
        }

        Http::sendResponse($response);
    }

    /**
     * @return string[]
     */
    protected function getChainedMessages(Exception $ex): array
    {
        $messages = [];
        while ($ex) {
            $line = $ex->getFile() . ":" . $ex->getLine();
            $message = $ex->getMessage();
            if ($this->debug) {
                $message .= " ($line)";
            }
            $messages[] = $message;
            $ex = $ex->getPrevious();
        }
        $messages = array_reverse($messages);
        return $messages;
    }
}
