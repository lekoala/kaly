<?php

declare(strict_types=1);

namespace Kaly\Core;

use Closure;
use Error;
use InvalidArgumentException;
use Kaly\Di\Definitions;
use Kaly\Router\Routes;
use Kaly\Util\Fs;
use Kaly\Util\Str;
use Kaly\View\RendererInterface;
use Kaly\View\TemplatePathRegistryInterface;

/**
 * A folder of the modules directory:
 *
 * ```text
 * modules/shop/
 *   config.php     services and module settings (required, may be empty)
 *   routes.php     explicit routes (optional)
 *   src/           Shop\... classes, Shop\Controller\... controllers
 *   templates/     registered as @shop in the renderer
 * ```
 *
 * config.php returns a closure, like routes.php:
 *
 * ```php
 * return static function (Module $module, Definitions $di): void {
 *     $module->priority(50)->mount('boutique');
 *     $di->bind(PaymentGateway::class, StripeGateway::class);
 * };
 * ```
 *
 * Every module is routable by convention under its decamelized name
 * (`modules/Shop` answers on `/shop/...`), except the one whose namespace is
 * the default one (`App`), which answers without prefix.
 */
final class Module
{
    private string $dir;
    private string $name;
    private string $namespace;
    private ?int $priority = null;
    private ?string $mount = null;
    private bool $conventionRouting = true;
    private Definitions $definitions;
    /**
     * @var list<Closure(Definitions): void>
     */
    private array $whenAllLoaded = [];

    public function __construct(string $dir)
    {
        if (!is_dir($dir)) {
            throw new InvalidArgumentException("Module directory '{$dir}' does not exist");
        }
        $this->dir = Fs::dir($dir);
        $this->name = basename($this->dir);
        // It's already uppercased, and we dont want to convert MyModule to Mymodule
        $this->namespace = strtoupper($this->name[0]) === $this->name[0] ? $this->name : Str::camelize($this->name);
        $this->definitions = new Definitions();
    }

    public static function fromConfig(string $file): self
    {
        return new self(dirname($file));
    }

    // region Configuration, from config.php

    /**
     * Lower priorities are configured first, so a higher priority module can
     * override their services. Defaults to the discovery order (100, 200...).
     */
    public function priority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * The root namespace of the module classes, the camelized folder name by default
     */
    public function namespace(string $namespace): self
    {
        $this->namespace = trim($namespace, '\\');
        return $this;
    }

    /**
     * The url segment under which conventional routes are exposed
     */
    public function mount(string $segment): self
    {
        $this->mount = trim($segment, '/');
        return $this;
    }

    /**
     * Only expose what routes.php and #[RouteAttribute] declare
     */
    public function withoutConventionRouting(): self
    {
        $this->conventionRouting = false;
        return $this;
    }

    /**
     * Adapt to the other modules: the callback receives the merged definitions
     * of every module, once they are all loaded.
     *
     * @param Closure(Definitions): void $callback
     */
    public function whenAllLoaded(Closure $callback): self
    {
        $this->whenAllLoaded[] = $callback;
        return $this;
    }

    public function definitions(): Definitions
    {
        return $this->definitions;
    }

    // endregion

    // region Read

    public function getDir(): string
    {
        return $this->dir;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function getMount(): string
    {
        return $this->mount ?? Str::decamelize($this->name);
    }

    public function hasConventionRouting(): bool
    {
        return $this->conventionRouting;
    }

    /**
     * @return list<Closure(Definitions): void>
     */
    public function getWhenAllLoaded(): array
    {
        return $this->whenAllLoaded;
    }

    public function getConfigPath(): string
    {
        return $this->dir . '/config.php';
    }

    public function getRoutesPath(): string
    {
        return $this->dir . '/routes.php';
    }

    public function hasRoutes(): bool
    {
        return is_file($this->getRoutesPath());
    }

    public function getSrcDir(): string
    {
        return $this->dir . '/src';
    }

    public function getTemplatesDir(): string
    {
        return $this->dir . '/templates';
    }

    public function hasTemplates(): bool
    {
        return is_dir($this->getTemplatesDir());
    }

    public function getAssetsDir(): string
    {
        return $this->dir . '/assets';
    }

    public function hasAssets(): bool
    {
        return is_dir($this->getAssetsDir());
    }

    // endregion

    /**
     * Modules need a config.php file (even if it's empty). This avoids having is_file checks in the loop
     * @return array<string>
     */
    public static function findModulesInDir(string $dir): array
    {
        // Sort results by name so that module discovery is deterministic
        // across filesystems. Priority is assigned in this order.
        $files = glob($dir . '/*/config.php');
        if (!$files) {
            $files = [];
        }
        sort($files);
        return $files;
    }

    /**
     * Autoloading should really be handled by composer but this helps
     */
    public function autoloadFiles(): void
    {
        spl_autoload_register(function (string $class): void {
            // Namespace doesn't match (could be A\Multiple\Separator\MyClass)
            $prefix = $this->namespace . '\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            // Look for a file in src directory
            $file = $this->getSrcDir() . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    public function loadConfig(): void
    {
        // Set templates dir automatically for renderers that support paths
        if ($this->hasTemplates()) {
            $this->definitions->callback(RendererInterface::class, function (RendererInterface $renderer): void {
                if ($renderer instanceof TemplatePathRegistryInterface) {
                    $renderer->setPath($this->name, $this->getTemplatesDir());
                }
            });
        }

        $config = $this->includeFile($this->getConfigPath());
        if ($config instanceof Closure) {
            $config($this, $this->definitions);
        } elseif ($config !== null) {
            throw new Ex("Module '{$this->name}' config.php must return a function (Module \$module, Definitions \$di): void");
        }

        $this->definitions->lock();
    }

    /**
     * Runs the module routes.php against the shared Routes builder.
     *
     * The file must return a `static function (Routes $routes): void` closure
     * (or nothing for an intentionally empty surface). Opening routes.php
     * shows the whole public HTTP surface of the module.
     */
    public function loadRouteDefinitions(Routes $routes): void
    {
        if (!$this->hasRoutes()) {
            return;
        }
        $callback = $this->includeFile($this->getRoutesPath());
        if ($callback === null) {
            return;
        }
        if (!$callback instanceof Closure) {
            throw new Ex("Module '{$this->name}' routes.php must return a function (Routes \$routes): void");
        }
        $callback($routes);
    }

    /**
     * Include a module file in an empty scope: no local variable and no $this
     * leak into it. A file that returns nothing gives null.
     */
    private function includeFile(string $file): mixed
    {
        $includer = static fn(string $file): mixed => require $file;
        try {
            $result = $includer($file);
        } catch (Error $e) {
            if (str_contains($e->getMessage(), '$this')) {
                throw new Ex(
                    "Module '{$this->name}' "
                    . basename($file)
                    . ' uses $this: return a function (Module $module, Definitions $di): void instead',
                    0,
                    $e,
                );
            }
            throw $e;
        }
        return $result === 1 ? null : $result;
    }
}
