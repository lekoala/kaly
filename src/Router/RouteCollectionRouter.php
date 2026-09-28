<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Http\MethodNotAllowedException;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Matches requests against a compiled RouteCollection.
 *
 * Knows nothing about routes.php or attributes: both feed plain
 * RouteDefinitions, so there is a single matching behavior, a single 405
 * policy and a single generate() whatever the declaration source.
 */
class RouteCollectionRouter implements RouterInterface
{
    /**
     * @var string[]
     */
    protected array $allowedLocales = [];
    protected bool $forceTrailingSlash = true;

    public function __construct(
        protected RouteCollection $collection,
    ) {}

    public function match(ServerRequestInterface $request): Route
    {
        RedirectUris::ensureTrailingSlash($request, $this->forceTrailingSlash);

        $path = $request->getUri()->getPath();
        $locale = $this->stripLocale($path);

        $method = strtoupper($request->getMethod());
        $allowed = [];

        foreach ($this->collection->entries() as $entry) {
            $matches = [];
            if (preg_match($entry['regex'], $path, $matches) !== 1) {
                continue;
            }

            $definitionMethods = array_map(strtoupper(...), $entry['definition']->methods);
            if ($definitionMethods !== [] && !in_array($method, $definitionMethods, true)) {
                foreach ($definitionMethods as $m) {
                    $allowed[$m] = true;
                }
                continue;
            }

            $params = $this->coerceParams($entry, $matches);
            if ($params === null) {
                continue;
            }

            $definition = $entry['definition'];
            $route = new Route();
            $route->segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $p): bool => $p !== ''));
            $route->locale = $locale ?? $this->allowedLocales[0] ?? null;
            $route->controller = $definition->controller;
            $route->action = $definition->action;
            $route->params = $params;
            $route->inputClass = $entry['inputClass'];
            $route->middlewares = $entry['middlewares'];
            $route->definition = $definition;
            $module = explode('\\', ltrim($definition->controller, '\\'))[0];
            $route->module = $module !== '' ? $module : null;
            $route->namespace = $route->module;
            return $route;
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedException(array_keys($allowed), "Method {$method} is not allowed for '{$path}'");
        }
        throw new RouteNotFoundException("Route '{$path}' not found");
    }

    /**
     * @param string|array<mixed> $handler Route name, Class::method, [class, method] or route array.
     * @param array<string,mixed> $params
     */
    public function generate($handler, array $params = []): string
    {
        $locale = $params['locale'] ?? null;
        unset($params['locale']);

        $definition = $this->findDefinition($handler);
        if ($definition === null) {
            throw new RuntimeException('Cannot generate an url without a matching explicit route');
        }

        $url = $definition->path;
        foreach (self::placeholderNames($definition->path) as $name) {
            if (!array_key_exists($name, $params)) {
                if (array_key_exists($name, $definition->defaults)) {
                    $default = $definition->defaults[$name];
                    if (!is_scalar($default)) {
                        throw new RuntimeException("Default parameter '{$name}' is not scalar");
                    }
                    $value = (string) $default;
                } else {
                    throw new RuntimeException("Missing parameter '{$name}' for route generation");
                }
            } else {
                $raw = $params[$name];
                if (!is_scalar($raw)) {
                    throw new RuntimeException("Parameter '{$name}' is not scalar");
                }
                $value = (string) $raw;
                unset($params[$name]);
            }
            $url = str_replace('{' . $name . '}', $value, $url);
        }
        if (is_string($locale) && $locale !== '' && $url !== '' && $url !== '/') {
            $url = '/' . $locale . $url;
        }
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }
        if ($this->forceTrailingSlash && !str_ends_with($url, '/')) {
            $url .= '/';
        }
        return $url;
    }

    /**
     * @param string|array<mixed> $handler
     */
    private function findDefinition($handler): ?RouteDefinition
    {
        if (is_string($handler) && !str_contains($handler, '::') && !str_contains($handler, '->') && !str_contains($handler, '\\')) {
            foreach ($this->collection->definitions() as $definition) {
                if ($definition->name === $handler) {
                    return $definition;
                }
            }
            return null;
        }
        if (is_string($handler)) {
            $handler = str_replace('->', '::', $handler);
            $parts = explode('::', $handler);
            $class = $parts[0];
            $method = $parts[1] ?? null;
        } elseif (array_is_list($handler)) {
            $class = $handler[0] ?? null;
            $method = $handler[1] ?? null;
        } else {
            $class = $handler[RouterInterface::CONTROLLER] ?? null;
            $method = $handler[RouterInterface::ACTION] ?? null;
        }
        if (!is_string($class) || $class === '') {
            return null;
        }
        $candidates = [];
        foreach ($this->collection->definitions() as $definition) {
            if ($definition->controller === $class && ($method === null || $definition->action === $method)) {
                $candidates[] = $definition;
            }
        }
        if (count($candidates) > 1) {
            // One handler with several urls has no canonical url: name it.
            throw new AmbiguousRouteException(sprintf(
                "Ambiguous handler '%s::%s' (%s): generate() by handler requires exactly one explicit route, use a route name instead",
                $class,
                is_string($method) ? $method : '*',
                implode(', ', array_map(static fn(RouteDefinition $d): string => $d->name ?? $d->path, $candidates)),
            ));
        }
        return $candidates[0] ?? null;
    }

    private function stripLocale(string &$path): ?string
    {
        if ($this->allowedLocales === []) {
            return null;
        }
        $trimmed = trim($path, '/');
        $first = explode('/', $trimmed)[0] ?? '';
        if (in_array(strtolower($first), $this->allowedLocales, true)) {
            $rest = substr($trimmed, strlen($first));
            $path = '/' . ltrim($rest, '/');
            return strtolower($first);
        }
        return null;
    }

    /**
     * Builds positional action arguments in signature order.
     *
     * @param array{regex:string,paramNames:list<string>,definition:RouteDefinition,inputClass:class-string<\Kaly\Http\RequestInput>|null,middlewares:list<class-string>,reflection:ReflectionClass<object>} $entry
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
        $placeholders = array_flip($entry['paramNames']);
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
        foreach (array_keys($placeholders) as $placeholder) {
            $found = false;
            foreach ($actionParams as $param) {
                if ($param->getName() === $placeholder) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return null;
            }
        }
        return $out;
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
     * @return string[]
     */
    public function getAllowedLocales(): array
    {
        return $this->allowedLocales;
    }

    /**
     * @param string[] $allowedLocales
     */
    public function setAllowedLocales(array $allowedLocales): self
    {
        $this->allowedLocales = array_map(strtolower(...), $allowedLocales);
        return $this;
    }

    public function getForceTrailingSlash(): bool
    {
        return $this->forceTrailingSlash;
    }

    public function setForceTrailingSlash(bool $forceTrailingSlash): self
    {
        $this->forceTrailingSlash = $forceTrailingSlash;
        return $this;
    }
}
