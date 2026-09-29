<?php

declare(strict_types=1);

namespace Kaly\Core;

use Closure;
use Error;
use InvalidArgumentException;
use Kaly\Di\Definitions;
use Kaly\Router\ResolverInterface;
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
 *   config.php     everything the module declares: services and routing
 *                  (required, may be empty)
 *   src/           Shop\... classes, Shop\Controller\... controllers
 *   templates/     registered as @shop in the renderer
 * ```
 *
 * config.php is the only file booted per module, and the one place to look
 * at to know how the module behaves:
 *
 * ```php
 * return static function (Module $module, Definitions $di): void {
 *     $di->bind(PaymentGateway::class, StripeGateway::class);
 *
 *     $module
 *         ->mount(['fr' => 'boutique', 'en' => 'shop'])
 *         ->localized()
 *         ->routes(function (Routes $routes): void {
 *             $routes->get('/produit/{slug}', [ProductController::class, 'show'])->name('product');
 *         });
 * };
 * ```
 *
 * The module resolves every url below its entry point with its resolvers, by
 * priority: its route tables, custom resolvers, and the convention last
 * (`controller/action/params`). Every module is mounted under its
 * decamelized name (`modules/Shop` answers on `/shop/...`), except the one
 * whose namespace is the default one (`App`), which answers without prefix.
 */
final class Module
{
    private string $dir;
    private string $name;
    private string $namespace;
    private ?int $priority = null;
    /**
     * @var array<string,string>|null Segment by locale, '*' for every locale
     */
    private ?array $mount = null;
    private bool $localized = false;
    private bool $conventionRouting = true;
    /**
     * @var list<array{priority:int,resolver:ResolverInterface|class-string<ResolverInterface>|Closure(Routes): void}>
     */
    private array $resolvers = [];
    /**
     * @var list<array{prefix:array<string,string>,routes:Closure(Routes): void}>
     */
    private array $claims = [];
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
     * The url segment the module answers under, its decamelized name by
     * default. A localized module can have one segment per locale.
     *
     * @param string|array<string,string> $segment 'shop', or ['fr' => 'boutique', 'en' => 'shop']
     */
    public function mount(string|array $segment): self
    {
        $segments = is_array($segment) ? $segment : ['*' => $segment];
        $this->mount = array_map(static fn(string $s): string => trim($s, '/'), $segments);
        return $this;
    }

    /**
     * Its urls carry the locale prefix (/fr/boutique/...). The locales are the
     * ones of the application (APP_LOCALES).
     */
    public function localized(bool $localized = true): self
    {
        $this->localized = $localized;
        return $this;
    }

    /**
     * Declare routes below the module entry point. Paths are relative to it:
     * in a module mounted on 'shop', '/cart' answers on /shop/cart/.
     *
     * Route names are local to the module, and qualified from the outside:
     * `shop:cart` (the default module may omit its prefix).
     *
     * @param Closure(Routes): void $routes
     */
    public function routes(Closure $routes, int $priority = 0): self
    {
        $this->resolvers[] = ['priority' => $priority, 'resolver' => $routes];
        return $this;
    }

    /**
     * Resolve urls of the module with a custom resolver, eg: pages stored in
     * a database. It runs by priority among the route tables (0) and the
     * convention (1000), and returns null for urls it does not know.
     *
     * @param ResolverInterface|class-string<ResolverInterface> $resolver A class is resolved from the container
     */
    public function resolver(ResolverInterface|string $resolver, int $priority = 0): self
    {
        $this->resolvers[] = ['priority' => $priority, 'resolver' => $resolver];
        return $this;
    }

    /**
     * Own a path outside of the module segment, with its own route table.
     * Paths of the table are relative to the claimed prefix. Claims are
     * explicit on purpose: two claims on the same prefix, or a claim inside
     * another module segment, fail at boot.
     *
     * @param string|array<string,string> $prefix '/about', or ['fr' => '/a-propos', 'en' => '/about']
     * @param Closure(Routes): void $routes
     */
    public function claim(string|array $prefix, Closure $routes): self
    {
        $prefixes = is_array($prefix) ? $prefix : ['*' => $prefix];
        $this->claims[] = [
            'prefix' => array_map(static fn(string $p): string => '/' . trim($p, '/'), $prefixes),
            'routes' => $routes,
        ];
        return $this;
    }

    /**
     * Only expose what the route tables and custom resolvers declare
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

    /**
     * The identity of the module in route names (`shop:cart`)
     */
    public function getId(): string
    {
        return Str::decamelize($this->name);
    }

    /**
     * @return array<string,string> Segment by locale, '*' for every locale
     */
    public function getMount(): array
    {
        return $this->mount ?? ['*' => $this->getId()];
    }

    public function isLocalized(): bool
    {
        return $this->localized;
    }

    public function hasConventionRouting(): bool
    {
        return $this->conventionRouting;
    }

    /**
     * @return list<array{priority:int,resolver:ResolverInterface|class-string<ResolverInterface>|Closure(Routes): void}>
     */
    public function getResolvers(): array
    {
        return $this->resolvers;
    }

    /**
     * @return list<array{prefix:array<string,string>,routes:Closure(Routes): void}>
     */
    public function getClaims(): array
    {
        return $this->claims;
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
