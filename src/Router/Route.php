<?php

declare(strict_types=1);

namespace Kaly\Router;

class Route
{
    public ?string $locale = null;
    /**
     * @var array<string>
     */
    public array $segments = [];
    public ?string $module = null;
    public ?string $namespace = null;
    /**
     * @var class-string
     */
    public ?string $controller = null;
    public ?string $action = null;
    /**
     * @var array<int<0,max>|string,mixed>
     */
    public array $params = [];
    /**
     * The trailing input of the action, built from the query and the body by
     * the dispatcher. Route segments are never mapped into it.
     * @var class-string<\Kaly\Http\RequestInput>|null
     */
    public ?string $inputClass = null;
    /**
     * The middlewares scoped to this route, outermost first: route table
     * groups and route, then `#[Middleware]` on parent classes, the class and
     * the method. The framework runs them right before the controller.
     *
     * @var list<class-string>
     */
    public array $middlewares = [];
    /**
     * The explicit definition this match came from, if any.
     *
     * Null for conventional and custom resolver matches.
     */
    public ?RouteDefinition $definition = null;
    /**
     * The qualified name of the route (`module:name`), if it has one
     */
    public ?string $name = null;
    /**
     * Objects resolved along with the route, handed to the controller
     * constructor by name: `['page' => $page]` fills `PageController(Page $page)`.
     *
     * @var array<string,mixed>
     */
    public array $bindings = [];

    /**
     * Build a route from a custom resolver
     *
     * @param class-string $controller
     * @param array<int<0,max>|string,mixed> $params Action arguments
     * @param array<string,mixed> $bindings Controller constructor arguments, by name
     */
    public static function to(
        string $controller,
        string $action = RouterInterface::FALLBACK_ACTION,
        array $params = [],
        array $bindings = [],
    ): self {
        $route = new self();
        $route->controller = $controller;
        $route->action = $action;
        $route->params = $params;
        $route->bindings = $bindings;
        return $route;
    }

    /**
     * The module namespace of a controller: what comes before its
     * `\Controller\` part (Shop\Controller\CartController gives Shop).
     */
    public static function moduleOf(string $controller): ?string
    {
        $controller = ltrim($controller, '\\');
        $pos = strpos($controller, '\\Controller\\');
        if ($pos !== false) {
            return substr($controller, 0, $pos);
        }
        $first = explode('\\', $controller)[0];
        return $first !== '' ? $first : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'segments' => $this->segments,
            'module' => $this->module,
            'namespace' => $this->namespace,
            'controller' => $this->controller,
            'action' => $this->action,
            'params' => $this->params,
            'inputClass' => $this->inputClass,
            'middlewares' => $this->middlewares,
            'name' => $this->name,
            'bindings' => array_keys($this->bindings),
        ];
    }
}
