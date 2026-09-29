<?php

declare(strict_types=1);

namespace Kaly\Router;

use Closure;
use Kaly\Core\Ex;
use Kaly\Http\MethodNotAllowedException;
use ReflectionNamedType;
use RuntimeException;

/**
 * Resolves the local route table of a module entry point.
 *
 * The table is declared in the module config.php and compiled on its first
 * use, then kept in memory: a request only pays for the module it reaches.
 * A path known for other methods only is an authoritative 405: the resolvers
 * that follow never get a chance to reinterpret it.
 *
 * @phpstan-import-type RouteEntry from RouteCollection
 */
final class TableResolver implements ResolverInterface
{
    private ?RouteCollection $collection = null;

    /**
     * @param Closure(Routes): void $declare
     * @param string $label Where the table is declared, for error messages
     * @param bool $localized Whether the owning module carries the locale prefix
     */
    public function __construct(
        private Closure $declare,
        private string $label = 'route table',
        private bool $localized = false,
    ) {}

    public function collection(): RouteCollection
    {
        if ($this->collection === null) {
            try {
                $routes = new Routes();
                ($this->declare)($routes);
                $definitions = $routes->definitions();
                $this->failOnLocalesWithoutPrefix($definitions);
                $this->collection = new RouteCollection($definitions);
            } catch (Ex $e) {
                throw new Ex("Invalid {$this->label}: {$e->getMessage()}", 0, $e);
            }
        }
        return $this->collection;
    }

    public function resolve(RouteRequest $request): ?Route
    {
        $path = $request->path();
        $method = $request->method();
        $allowed = [];

        foreach ($this->collection()->entries() as $entry) {
            if ($entry['locale'] !== null && $entry['locale'] !== $request->locale) {
                continue;
            }
            $matches = [];
            if (preg_match($entry['regex'], $path, $matches) !== 1) {
                continue;
            }

            $methods = array_map(strtoupper(...), $entry['definition']->methods);
            if ($methods !== [] && !in_array($method, $methods, true)) {
                foreach ($methods as $m) {
                    $allowed[$m] = true;
                }
                continue;
            }

            $params = $this->coerceParams($entry, $matches);
            if ($params === null) {
                continue;
            }

            $definition = $entry['definition'];
            $route = Route::to($definition->controller, $definition->action, $params);
            $route->inputClass = $entry['inputClass'];
            $route->middlewares = $entry['middlewares'];
            $route->definition = $definition;
            return $route;
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedException(array_keys($allowed), "Method {$method} is not allowed for '{$path}'");
        }
        return null;
    }

    /**
     * The path of a named route, relative to the entry point, without query
     *
     * @param array<string,mixed> $params Consumed placeholders are removed
     */
    public function path(RouteDefinition $definition, array &$params, ?string $locale): string
    {
        if ($locale !== null) {
            $paths = $definition->paths();
            if (!isset($paths[$locale]) && !isset($paths['*'])) {
                throw new RuntimeException(
                    "Route '{$definition->name}' has no path for locale '{$locale}': generating it would produce an url no route matches",
                );
            }
        }
        $path = $definition->pathFor($locale);
        foreach (RouteCollection::placeholderNames($path) as $name) {
            if (array_key_exists($name, $params)) {
                $value = $params[$name];
                unset($params[$name]);
            } elseif (array_key_exists($name, $definition->defaults)) {
                $value = $definition->defaults[$name];
            } else {
                throw new RuntimeException("Missing parameter '{$name}' for route '{$definition->name}'");
            }
            if (!is_scalar($value)) {
                throw new RuntimeException("Parameter '{$name}' of route '{$definition->name}' is not scalar");
            }
            $path = str_replace('{' . $name . '}', rawurlencode((string) $value), $path);
        }
        return rtrim($path, '/');
    }

    /**
     * Locale-keyed paths only make sense with the locale prefix: without
     * localized(), generation would produce urls the matcher resolves with
     * the default locale and answers 404.
     *
     * @param list<RouteDefinition> $definitions
     */
    private function failOnLocalesWithoutPrefix(array $definitions): void
    {
        if ($this->localized) {
            return;
        }
        foreach ($definitions as $definition) {
            $locales = array_keys($definition->paths());
            if ($locales !== ['*']) {
                throw new Ex(sprintf(
                    "Route '%s' has paths per locale (%s) but its module is not localized: call localized() or declare a single path",
                    $definition->pathFor(null),
                    implode(', ', $locales),
                ));
            }
        }
    }

    /**
     * Builds positional action arguments in signature order.
     *
     * @param RouteEntry $entry
     * @param array<array-key,string> $matches
     * @return array<int<0,max>|string,mixed>|null Null when the entry does not match.
     */
    private function coerceParams(array $entry, array $matches): ?array
    {
        $reflection = $entry['reflection'];
        $action = $entry['definition']->action;
        if (!$reflection->hasMethod($action)) {
            return null;
        }
        $actionParams = $reflection->getMethod($action)->getParameters();
        if ($entry['inputClass'] !== null && $actionParams !== []) {
            array_pop($actionParams);
        }
        $out = [];
        foreach ($actionParams as $param) {
            if ($param->isVariadic()) {
                continue;
            }
            $name = $param->getName();
            if (array_key_exists($name, $matches) && $matches[$name] !== '') {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                    try {
                        $out[] = RouteParamCoercer::coerce($type->getName(), $matches[$name]);
                    } catch (RouteNotFoundException) {
                        return null;
                    }
                } else {
                    $out[] = $matches[$name];
                }
                continue;
            }
            if (array_key_exists($name, $entry['definition']->defaults)) {
                $out[] = $entry['definition']->defaults[$name];
                continue;
            }
            if ($param->isOptional() || $param->isDefaultValueAvailable()) {
                $out[] = $param->getDefaultValue();
                continue;
            }
            return null;
        }
        // Every placeholder must feed an action parameter: a stray placeholder
        // is a declaration mistake, not a match.
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $actionParams);
        foreach ($entry['paramNames'] as $placeholder) {
            if (!in_array($placeholder, $names, true)) {
                return null;
            }
        }
        return $out;
    }
}
