<?php

declare(strict_types=1);

namespace Kaly\Router;

use Closure;
use Kaly\Core\Ex;
use Kaly\Core\Module;
use Kaly\Http\RedirectException;
use Kaly\Util\Str;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Hierarchical router: every url belongs to exactly one module, which
 * resolves it alone.
 *
 * ```text
 * /fr/boutique/panier/ajouter/42
 *  │    │         └── resolved by the Shop module resolvers, by priority
 *  │    └── entry point: claim (longest prefix) > mount > default module
 *  └── locale, when the application declares locales
 * ```
 *
 * Nothing is global: a module never sees the urls of another one, and
 * resolving a request costs a lookup plus the resolvers of a single module,
 * whatever the size of the application. Everything a module answers is
 * declared in its config.php.
 *
 * The router is a shared service built at boot: route tables are compiled on
 * their first use and kept in memory, the per-request state lives in locals.
 */
final class Router implements RouterInterface
{
    public const DEFAULT_NAMESPACE = 'App';

    /**
     * @var array<string,Module> By module id
     */
    private array $modules = [];

    /**
     * @var array<string,string> Module namespace => module id
     */
    private array $namespaces = [];

    /**
     * @var array<string,list<ResolverInterface|class-string<ResolverInterface>>> By module id, in priority order
     */
    private array $resolvers = [];

    /**
     * @var array<string,list<TableResolver>> The route tables of each module entry point, by module id
     */
    private array $tables = [];

    /**
     * @var array<string,array<string,string>> Locale ('*' for any) => segment => module id
     */
    private array $mounts = [];

    /**
     * @var list<array{id:string,locale:?string,segments:list<string>,table:TableResolver}> Longest first
     */
    private array $claims = [];

    private ?string $default = null;
    private ConventionResolver $convention;

    /**
     * @param list<Module> $modules
     * @param list<string> $locales The application locales, the first one is the default
     */
    public function __construct(
        array $modules,
        private ?ContainerInterface $container = null,
        private array $locales = [],
        private bool $forceTrailingSlash = true,
    ) {
        $this->locales = array_values(array_map(strtolower(...), $locales));
        $this->convention = new ConventionResolver($forceTrailingSlash);

        foreach ($modules as $module) {
            $this->register($module);
        }
        foreach ($modules as $module) {
            $this->registerClaims($module);
        }
        usort($this->claims, static fn(array $a, array $b): int => count($b['segments']) <=> count($a['segments']));
    }

    // region Matching

    public function match(ServerRequestInterface $request): Route
    {
        RedirectUris::ensureTrailingSlash($request, $this->forceTrailingSlash);

        $path = $request->getUri()->getPath();
        $all = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $p): bool => $p !== ''));
        $segments = $all;

        // Maybe a locale as a prefix
        $locale = null;
        if ($this->locales !== [] && isset($segments[0]) && in_array(strtolower($segments[0]), $this->locales, true)) {
            $given = (string) array_shift($segments);
            $locale = strtolower($given);
            // The default locale alone is the home page
            if ($segments === [] && $locale === $this->locales[0]) {
                throw $this->redirect($request, $given, '');
            }
        }
        $effective = $locale ?? $this->locales[0] ?? null;

        [$id, $entry, $remaining, $table] = $this->entry($request, $segments, $effective);
        $module = $this->modules[$id];

        // One canonical url: the locale prefix is there if and only if the
        // module is localized
        $localized = $module->isLocalized() && $this->locales !== [];
        if ($locale !== null && !$localized) {
            throw $this->redirect($request, $all[0], '');
        }
        if ($locale === null && $localized && count($this->locales) > 1 && $all !== []) {
            $uri = $request->getUri();
            throw new RedirectException($uri->withPath('/' . $this->locales[0] . '/' . ltrim($uri->getPath(), '/')));
        }

        $prefix = ($locale !== null ? '/' . $locale : '') . $entry;
        $routeRequest = new RouteRequest($request, $remaining, $prefix, $module->getNamespace(), $effective);

        foreach ($table !== null ? [$table] : $this->resolvers[$id] as $resolver) {
            if (is_string($resolver)) {
                $resolver = $this->resolveService($resolver);
            }
            $route = $resolver->resolve($routeRequest);
            if ($route === null) {
                continue;
            }
            $route->locale = $effective;
            $route->segments = $all;
            $route->module = $module->getNamespace();
            $route->namespace = $module->getNamespace();
            if ($route->name === null && $route->definition?->name !== null) {
                $route->name = $id . ':' . $route->definition->name;
            }
            return $route;
        }

        throw new RouteNotFoundException("Route '{$path}' not found in module '{$id}'");
    }

    /**
     * Find the entry point of a path: a claim (longest prefix first), a
     * mounted segment, or the default module.
     *
     * @param list<string> $segments
     * @return array{0:string,1:string,2:list<string>,3:?TableResolver} Module id, consumed entry, remaining segments, claim table
     */
    private function entry(ServerRequestInterface $request, array $segments, ?string $locale): array
    {
        foreach ($this->claims as $claim) {
            if ($claim['locale'] !== null && $claim['locale'] !== $locale) {
                continue;
            }
            $size = count($claim['segments']);
            if (array_slice($segments, 0, $size) === $claim['segments']) {
                return [$claim['id'], '/' . implode('/', $claim['segments']), array_slice($segments, $size), $claim['table']];
            }
        }

        $first = $segments[0] ?? null;
        if ($first !== null) {
            $canonical = Str::decamelize($first);
            $id = $this->mounts[$locale ?? '*'][$canonical] ?? $this->mounts['*'][$canonical] ?? null;
            if ($id !== null) {
                // A single canonical url: /Shop/ or /SHOP/ redirect to /shop/
                if ($first !== $canonical) {
                    throw $this->redirect($request, $first, $canonical);
                }
                return [$id, '/' . $canonical, array_slice($segments, 1), null];
            }
        }

        if ($this->default === null) {
            throw new RouteNotFoundException("Route '{$request->getUri()->getPath()}' not found");
        }
        return [$this->default, '', $segments, null];
    }

    // endregion

    // region Generation

    public function url(string $name, array $params = [], ?string $locale = null): string
    {
        [$id, $local] = $this->splitName($name);
        $locale = $this->localeFor($locale);

        foreach ($this->tables[$id] ?? [] as $table) {
            $definition = $table->collection()->byName($local);
            if ($definition !== null) {
                $path = $table->path($definition, $params, $locale);
                return $this->build($id, $this->mountFor($id, $locale) . $path, $params, $locale);
            }
        }
        foreach ($this->claims as $claim) {
            if ($claim['id'] !== $id || $claim['locale'] !== null && $claim['locale'] !== $locale) {
                continue;
            }
            $definition = $claim['table']->collection()->byName($local);
            if ($definition !== null) {
                $path = $claim['table']->path($definition, $params, $locale);
                return $this->build($id, '/' . implode('/', $claim['segments']) . $path, $params, $locale);
            }
        }

        throw new RuntimeException("Unknown route '{$name}'");
    }

    public function urlFor(string|array $handler, array $params = [], ?string $locale = null): string
    {
        [$controller, $action] = Routes::normalizeHandler($handler);
        $namespace = (string) Route::moduleOf($controller);
        $id = $this->namespaces[$namespace] ?? null;
        if ($id === null) {
            throw new RuntimeException("No module is registered for '{$controller}'");
        }
        if (!$this->modules[$id]->hasConventionRouting()) {
            throw new RuntimeException("Module '{$id}' has no conventional urls: generate '{$controller}::{$action}' by its route name");
        }
        $locale = $this->localeFor($locale);
        $path = $this->convention->path($controller, $action, array_values($params));

        return $this->build($id, $this->mountFor($id, $locale) . $path, [], $locale);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitName(string $name): array
    {
        if (str_contains($name, ':')) {
            [$id, $local] = explode(':', $name, 2);
        } else {
            $id = $this->default ?? throw new RuntimeException("Route name '{$name}' must be qualified: 'module:name'");
            $local = $name;
        }
        if (!isset($this->modules[$id])) {
            throw new RuntimeException("Unknown module '{$id}' in route name '{$name}'");
        }
        return [$id, $local];
    }

    private function localeFor(?string $locale): ?string
    {
        if ($locale === null) {
            return $this->locales[0] ?? null;
        }
        $locale = strtolower($locale);
        if ($this->locales !== [] && !in_array($locale, $this->locales, true)) {
            throw new RuntimeException("Invalid locale '{$locale}'");
        }
        return $locale;
    }

    private function mountFor(string $id, ?string $locale): string
    {
        if ($id === $this->default) {
            return '';
        }
        $mount = $this->modules[$id]->getMount();
        return '/' . ($mount[$locale ?? '*'] ?? $mount['*'] ?? (string) reset($mount));
    }

    /**
     * @param array<string,mixed> $query
     */
    private function build(string $id, string $path, array $query, ?string $locale): string
    {
        if ($locale !== null && $this->modules[$id]->isLocalized() && $this->locales !== []) {
            // The home of the default locale has no prefix
            $path = $path === '' && $locale === $this->locales[0] ? '' : '/' . $locale . $path;
        }
        $url = $path === '' ? '/' : $path;
        if ($this->forceTrailingSlash && !str_ends_with($url, '/')) {
            $url .= '/';
        }
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    // endregion

    // region Introspection

    /**
     * @return list<string>
     */
    public function getLocales(): array
    {
        return $this->locales;
    }

    /**
     * @return array<string,array<string,string>> Locale ('*' for any) => segment => module id
     */
    public function getMounts(): array
    {
        return $this->mounts;
    }

    public function getDefaultModule(): ?string
    {
        return $this->default;
    }

    // endregion

    // region Registration

    private function register(Module $module): void
    {
        $id = $module->getId();
        if (isset($this->modules[$id])) {
            throw new Ex("Two modules share the id '{$id}'");
        }
        $this->modules[$id] = $module;
        $this->namespaces[$module->getNamespace()] = $id;

        if ($module->getNamespace() === self::DEFAULT_NAMESPACE) {
            $this->default = $id;
        } else {
            foreach ($module->getMount() as $locale => $segment) {
                $this->mount($id, $locale, $segment);
            }
        }

        // Resolvers by priority, then declaration order; the convention last
        $entries = [];
        $sequence = 0;
        $this->tables[$id] = [];
        foreach ($module->getResolvers() as $declared) {
            $resolver = $declared['resolver'];
            if ($resolver instanceof Closure) {
                $resolver = new TableResolver($resolver);
                $this->tables[$id][] = $resolver;
            }
            $entries[] = [$declared['priority'], $sequence++, $resolver];
        }
        if ($module->hasConventionRouting()) {
            $entries[] = [ConventionResolver::PRIORITY, $sequence, $this->convention];
        }
        usort($entries, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $this->resolvers[$id] = array_map(static fn(array $e): ResolverInterface|string => $e[2], $entries);
    }

    private function mount(string $id, string $locale, string $segment): void
    {
        if ($segment === '' || $segment !== Str::decamelize($segment) || str_contains($segment, '/')) {
            throw new Ex("Module '{$id}' mount '{$segment}' must be a single lowercase url segment (eg: 'my-module')");
        }
        if (in_array($segment, $this->locales, true)) {
            throw new Ex("Module '{$id}' cannot be mounted on '{$segment}', it is a locale");
        }
        $taken =
            $this->mounts[$locale][$segment]
            ?? ($locale === '*' ? $this->takenInAnyLocale($segment) : $this->mounts['*'][$segment] ?? null);
        if ($taken !== null && $taken !== $id) {
            throw new Ex("Segment '{$segment}' is mounted by both '{$taken}' and '{$id}'");
        }
        $this->mounts[$locale][$segment] = $id;
    }

    private function takenInAnyLocale(string $segment): ?string
    {
        foreach ($this->mounts as $segments) {
            if (isset($segments[$segment])) {
                return $segments[$segment];
            }
        }
        return null;
    }

    private function registerClaims(Module $module): void
    {
        $id = $module->getId();
        foreach ($module->getClaims() as $claim) {
            $table = new TableResolver($claim['routes']);
            foreach ($claim['prefix'] as $locale => $prefix) {
                $segments = array_values(array_filter(explode('/', trim($prefix, '/')), static fn(string $p): bool => $p !== ''));
                if ($segments === []) {
                    throw new Ex("Module '{$id}' cannot claim the root: declare its routes in the default module");
                }
                $owner = $this->takenInAnyLocale($segments[0]);
                if ($owner !== null && $owner !== $id) {
                    throw new Ex("Module '{$id}' claims '{$prefix}' inside the segment of module '{$owner}'");
                }
                $claimLocale = $locale === '*' ? null : $locale;
                foreach ($this->claims as $existing) {
                    $sameLocale = $existing['locale'] === null || $claimLocale === null || $existing['locale'] === $claimLocale;
                    if ($sameLocale && $existing['segments'] === $segments) {
                        throw new Ex("Prefix '{$prefix}' is claimed by both '{$existing['id']}' and '{$id}'");
                    }
                }
                $this->claims[] = ['id' => $id, 'locale' => $claimLocale, 'segments' => $segments, 'table' => $table];
            }
        }
    }

    /**
     * @param class-string<ResolverInterface> $class
     */
    private function resolveService(string $class): ResolverInterface
    {
        $resolver = $this->container?->get($class);
        if (!$resolver instanceof ResolverInterface) {
            throw new Ex("Resolver '{$class}' must implement " . ResolverInterface::class);
        }
        return $resolver;
    }

    private function redirect(ServerRequestInterface $request, string $remove, string $replace): RedirectException
    {
        return new RedirectException(RedirectUris::replaceSegment($request, $remove, $replace, $this->forceTrailingSlash));
    }

    // endregion
}
