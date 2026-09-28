<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Core\Ex;
use Kaly\Http\RequestInput;
use ReflectionClass;
use ReflectionNamedType;

/**
 * A frozen, compiled set of explicit route definitions.
 *
 * Built once at boot from every module (routes.php through the Routes DSL
 * plus AttributeRouteLoader output — both are plain RouteDefinition lists).
 * Entries are sorted by priority then specificity; the sort is stable so
 * routes.php registrations win ties over attribute-loaded ones appended
 * later. Regexes are precompiled, so matching never reflects.
 */
final class RouteCollection
{
    /**
     * @var list<array{regex:string,paramNames:list<string>,definition:RouteDefinition,inputClass:class-string<RequestInput>|null,middlewares:list<class-string>,reflection:ReflectionClass<object>}>
     */
    private array $entries;

    /**
     * @var list<RouteDefinition>
     */
    private array $definitions;

    /**
     * @param list<RouteDefinition> $definitions
     */
    public function __construct(array $definitions)
    {
        $this->definitions = array_values($definitions);
        $this->failOnDuplicateNames($this->definitions);
        $this->failOnCollisions($this->definitions);
        $entries = [];
        foreach ($this->definitions as $definition) {
            $reflection = new ReflectionClass($definition->controller);
            $entries[] = [
                'regex' => self::compilePath($definition->path, $definition->requirements),
                'paramNames' => self::placeholderNames($definition->path),
                'definition' => $definition,
                'inputClass' => self::trailingInputClass($reflection, $definition->action),
                // Resolved and validated at boot: a declared middleware that
                // cannot run fails here, never silently at request time
                'middlewares' => RouteMiddlewares::merge(
                    RouteMiddlewares::normalize($definition->middlewares, "route '{$definition->path}'"),
                    RouteMiddlewares::ofAction($definition->controller, $definition->action),
                ),
                'reflection' => $reflection,
            ];
        }
        usort($entries, static function (array $a, array $b): int {
            if ($a['definition']->priority !== $b['definition']->priority) {
                return $b['definition']->priority <=> $a['definition']->priority;
            }
            return strlen($b['definition']->path) <=> strlen($a['definition']->path);
        });
        $this->entries = $entries;
    }

    /**
     * @return list<array{regex:string,paramNames:list<string>,definition:RouteDefinition,inputClass:class-string<RequestInput>|null,middlewares:list<class-string>,reflection:ReflectionClass<object>}>
     * @internal Consumed by RouteCollectionRouter only.
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<RouteDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function count(): int
    {
        return count($this->definitions);
    }

    /**
     * Human-readable snapshot in matching order, for inspection and debugging
     * (eg: a future debug:routes command). No behavior depends on it.
     *
     * @return list<array{path:string,methods:list<string>,name:?string,controller:class-string,action:string,priority:int,middlewares:list<class-string>}>
     */
    public function toArray(): array
    {
        return array_map(static fn(array $entry): array => [
            'path' => $entry['definition']->path,
            'methods' => self::normalizeMethods($entry['definition']->methods),
            'name' => $entry['definition']->name,
            'controller' => $entry['definition']->controller,
            'action' => $entry['definition']->action,
            'priority' => $entry['definition']->priority,
            'middlewares' => $entry['middlewares'],
        ], $this->entries);
    }

    /**
     * A name identifies exactly one definition: generate(name) must never
     * depend on matcher order or priority. Duplicates fail fast, whatever
     * their paths or priorities.
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
     * Two definitions with an equivalent match space, overlapping methods and
     * the same priority would resolve to "first sorted wins" silently.
     * Different priorities are an explicit precedence choice and stay
     * allowed; textually equal paths with disjoint methods (GET vs POST)
     * never collide.
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
                if ($a->priority !== $b->priority) {
                    continue;
                }
                if (!self::methodsOverlap($a->methods, $b->methods)) {
                    continue;
                }
                if (self::canonicalPattern($a) !== self::canonicalPattern($b)) {
                    continue;
                }
                throw new Ex(sprintf(
                    "Colliding routes '%s' (%s::%s) and '%s' (%s::%s) with the same priority %d: disambiguate with priorities or requirements",
                    $a->path,
                    $a->controller,
                    $a->action,
                    $b->path,
                    $b->controller,
                    $b->action,
                    $a->priority,
                ));
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
        return array_values(array_unique(array_map(strtoupper(...), $methods)));
    }

    /**
     * The match space of a definition with placeholder names erased:
     * '/users/{id}' and '/users/{slug}' both canonicalize to the same key,
     * while differing requirements stay distinct.
     */
    private static function canonicalPattern(RouteDefinition $definition): string
    {
        $path = rtrim($definition->path, '/');
        if ($path === '') {
            $path = '/';
        }
        return (string) preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn(array $m): string => '{' . ($definition->requirements[$m[1]] ?? '[^/]+') . '}',
            $path,
        );
    }

    /**
     * @return list<string>
     */
    private static function placeholderNames(string $path): array
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
            $path,
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
        $params = $reflection->getMethod($action)->getParameters();
        if ($params === []) {
            return null;
        }
        $last = $params[count($params) - 1];
        $type = $last->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }
        $name = $type->getName();
        if (!is_a($name, RequestInput::class, true)) {
            return null;
        }
        /** @var class-string<RequestInput> $name */
        return $name;
    }
}
