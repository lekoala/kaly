<?php

declare(strict_types=1);

namespace Kaly;

use RuntimeException;
use Kaly\Interfaces\RouterInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * A basic app that should be created from the entry file
 */
class App
{
    use AppRouter;

    public const MODULES_FOLDER = "modules";

    protected bool $debug;
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
                    $definitions = array_merge_recursive($definitions, $config);
                }
                return $definitions;
            };
            $definitions = $includer($file, $definitions);
        }
        $this->modules = $modules;
        $this->definitions = $definitions;
    }

    /**
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
        $definitions[ResponseFactoryInterface::class] = ResponseFactory::class;
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
     * Init app state
     */
    public function boot(): void
    {
        $this->loadEnv();
        $this->loadModules();
    }

    /**
     * Handle a request and send its response
     */
    public function handle(ServerRequestInterface $request = null): void
    {
        if (!$request) {
            $request = Http::createRequestFromGlobals();
        }
        // We need to configure the di for each request since
        // it can provide the request to the controller
        $di = $this->configureDi($request);
        $response = $this->processRequest($request, $di);
        Http::sendResponse($response);
    }

    public function run(ServerRequestInterface $request = null): void
    {
        $this->boot();
        $this->handle($request);
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
