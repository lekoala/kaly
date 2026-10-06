<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Ex;
use Kaly\Http\Input\RequestInput;
use Kaly\Http\Method;
use ReflectionClass;

/**
 * A frozen, compiled table of routes: the local routes of one module entry
 * point (its mount or one of its claims).
 *
 * Paths are relative to that entry point. A declaration with one path per
 * locale compiles to one entry per locale. Entries are sorted by priority
 * then specificity; the sort is stable so declaration order wins ties.
 * Regexes are precompiled, so matching never reflects.
 *
 * The table keeps its own copies of the declarations: every `RouteDefinition`
 * is cloned on construction, so mutating the handle a caller still holds
 * cannot desynchronize the precompiled regex from the path. The copies never
 * leak: `byName()` returns a clone too, and `definitions()` is gone.
 *
 * @internal
 *
 * @phpstan-type RouteEntry array{regex:string,locale:?string,paramNames:list<string>,definition:RouteDefinition,inputClass:class-string<RequestInput>|null,middlewares:list<class-string>,reflection:ReflectionClass<object>}
 */
final class RouteCollection
{
    /**
     * @var list<RouteEntry>
     */
    private array $entries;

    /**
     * The private copies of the declarations, one per route whatever its locale
     *
     * @var list<RouteDefinition>
     */
    private array $definitions;

    /**
     * @param list<RouteDefinition> $definitions
     */
    public function __construct(array $definitions)
    {
        $definitions = array_map(static fn(RouteDefinition $definition): RouteDefinition => clone $definition, array_values($definitions));
        $this->definitions = $definitions;
        $this->failOnDuplicateNames($definitions);
        $this->failOnCollisions($definitions);
        $entries = [];
        foreach ($definitions as $definition) {
            $reflection = new ReflectionClass($definition->controller);
            // Resolved and validated when the table is compiled: a declared
            // middleware that cannot run fails here, never silently
            $middlewares = Routes::normalizeMiddlewares($definition->middlewares, "route '" . $definition->pathFor(null) . "'");
            $inputClass = self::trailingInputClass($reflection, $definition->action);
            $paramNames = null;
            foreach ($definition->paths() as $locale => $path) {
                $names = self::placeholderNames($path);
                if ($paramNames !== null && $names !== $paramNames) {
                    throw new Ex("Every locale path of route '{$path}' must declare the same placeholders");
                }
                $paramNames = $names;
                $entries[] = [
                    'regex' => self::compilePath($path, $definition->requirements),
                    'locale' => $locale === '*' ? null : $locale,
                    'paramNames' => $names,
                    'definition' => $definition,
                    'inputClass' => $inputClass,
                    'middlewares' => $middlewares,
                    'reflection' => $reflection,
                ];
            }
        }
        usort($entries, static function (array $a, array $b): int {
            if ($a['definition']->priority !== $b['definition']->priority) {
                return $b['definition']->priority <=> $a['definition']->priority;
            }
            return strlen($b['regex']) <=> strlen($a['regex']);
        });
        $this->entries = $entries;
    }

    /**
     * Canonical identity of a controller action: PHP resolves classes and
     * methods case-insensitively, so ownership compares lowercase.
     *
     * @param class-string $controller
     */
    public static function canonicalTarget(string $controller, string $action): string
    {
        return strtolower(ltrim($controller, '\\')) . '::' . strtolower($action);
    }

    /**
     * Actions targeted by explicit declarations, for ownership checks.
     * Unlike toArray(), behavior depends on it.
     *
     * @return array<string,list<string|null>> Canonical action => route names (null when unnamed)
     */
    public function targetedActions(): array
    {
        $targeted = [];
        foreach ($this->definitions as $definition) {
            $targeted[self::canonicalTarget($definition->controller, $definition->action)][] = $definition->name;
        }
        return $targeted;
    }

    /**
     * @return list<RouteEntry>
     * @internal Matcher access only: the entries carry the private, mutable
     * `RouteDefinition` copies, so callers must never mutate them or hand them
     * out. Public inspection goes through toArray().
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * A copy of the declaration carrying a name, if any. A name identifies
     * exactly one declaration; duplicates already failed at compile time.
     *
     * The copy is deliberate: the caller may read it (url generation) but can
     * never mutate the table through it.
     */
    public function byName(string $name): ?RouteDefinition
    {
        foreach ($this->definitions as $definition) {
            if ($definition->name === $name) {
                return clone $definition;
            }
        }
        return null;
    }

    public function count(): int
    {
        return count($this->definitions);
    }

    /**
     * Human-readable snapshot in matching order, for inspection and debugging.
     * No behavior depends on it.
     *
     * @return list<array{path:string,locale:?string,methods:list<string>,name:?string,controller:class-string,action:string,priority:int,middlewares:list<class-string>}>
     */
    public function toArray(): array
    {
        return array_map(static fn(array $entry): array => [
            'path' => $entry['definition']->pathFor($entry['locale']),
            'locale' => $entry['locale'],
            'methods' => self::normalizeMethods($entry['definition']->methods),
            'name' => $entry['definition']->name,
            'controller' => $entry['definition']->controller,
            'action' => $entry['definition']->action,
            'priority' => $entry['definition']->priority,
            'middlewares' => $entry['middlewares'],
        ], $this->entries);
    }

    /**
     * A name identifies exactly one definition: generation must never depend
     * on matcher order or priority. Duplicates fail fast, whatever their paths
     * or priorities.
     *
     * @param list<RouteDefinition> $definitions
     */
    private function failOnDuplicateNames(array $definitions): void
    {
        $seen = [];
        foreach ($definitions as $definition) {
            if ($definition->name === null) {
                continue;
            }
            if (isset($seen[$definition->name])) {
                throw new Ex(sprintf(
                    "Duplicate route name '%s' (%s::%s and %s::%s): a name must identify exactly one route definition",
                    $definition->name,
                    $seen[$definition->name][0],
                    $seen[$definition->name][1],
                    $definition->controller,
                    $definition->action,
                ));
            }
            $seen[$definition->name] = [$definition->controller, $definition->action];
        }
    }

    /**
     * Two definitions with an equivalent match space (for a common locale),
     * overlapping methods and the same priority would resolve to "first
     * sorted wins" silently. Different priorities are an explicit precedence
     * choice and stay allowed; textually equal paths with disjoint methods
     * (GET vs POST) never collide.
     *
     * Equivalence is textual on the canonical pattern (placeholder names
     * ignored, effective fragments compared), not a regex-equivalence proof:
     * '\d+' vs '[0-9]+' will not be flagged.
     *
     * @param list<RouteDefinition> $definitions
     */
    private function failOnCollisions(array $definitions): void
    {
        $count = count($definitions);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $definitions[$i];
                $b = $definitions[$j];
                if ($a->priority !== $b->priority || !self::methodsOverlap($a->methods, $b->methods)) {
                    continue;
                }
                foreach ($a->paths() as $localeA => $pathA) {
                    foreach ($b->paths() as $localeB => $pathB) {
                        if ($localeA !== '*' && $localeB !== '*' && $localeA !== $localeB) {
                            continue;
                        }
                        if (self::canonicalPattern($pathA, $a->requirements) !== self::canonicalPattern($pathB, $b->requirements)) {
                            continue;
                        }
                        throw new Ex(sprintf(
                            "Colliding routes '%s' (%s::%s) and '%s' (%s::%s) with the same priority %d: disambiguate with priorities or requirements",
                            $pathA,
                            $a->controller,
                            $a->action,
                            $pathB,
                            $b->controller,
                            $b->action,
                            $a->priority,
                        ));
                    }
                }
            }
        }
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    private static function methodsOverlap(array $a, array $b): bool
    {
        $a = self::normalizeMethods($a);
        $b = self::normalizeMethods($b);
        if ($a === [] || $b === []) {
            return true;
        }
        return count(array_intersect($a, $b)) > 0;
    }

    /**
     * @param string[] $methods
     * @return list<string>
     */
    private static function normalizeMethods(array $methods): array
    {
        return Method::normalizeList($methods);
    }

    /**
     * The match space of a path with placeholder names erased:
     * '/users/{id}' and '/users/{slug}' both canonicalize to the same key,
     * while differing requirements stay distinct.
     *
     * @param array<string,string> $requirements
     */
    private static function canonicalPattern(string $path, array $requirements): string
    {
        $path = RoutePath::withoutTrailingSlash($path);
        return (string) preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn(array $m): string => '{' . ($requirements[$m[1]] ?? '[^/]+') . '}',
            $path,
        );
    }

    /**
     * @return list<string>
     */
    public static function placeholderNames(string $path): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $m);
        /** @var list<non-falsy-string> $names */
        $names = $m[1];
        return $names;
    }

    /**
     * @param array<string,string> $requirements
     */
    private static function compilePath(string $path, array $requirements): string
    {
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use ($requirements): string {
                $fragment = $requirements[$m[1]] ?? '[^/]+';
                return '(?P<' . $m[1] . '>' . $fragment . ')';
            },
            rtrim($path, '/'),
        );
        return '#^' . $regex . '/?$#';
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return class-string<RequestInput>|null
     */
    private static function trailingInputClass(ReflectionClass $reflection, string $action): ?string
    {
        if (!$reflection->hasMethod($action)) {
            return null;
        }
        return ActionSignature::inputOf($reflection->getMethod($action)->getParameters(), $action, $reflection->getName());
    }
}
