<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Http\Input\RequestInput;

/**
 * A resolved route: the final, immutable answer of a module resolver.
 *
 * Resolvers build it in one step from the RouteRequest they receive
 * (`$request->route(...)`), which already carries the module and the locale:
 * there is no mutable draft completed afterwards.
 */
final readonly class Route
{
    /**
     * @param class-string $controller
     * @param array<int<0,max>|string,mixed> $params Action arguments
     * @param array<string,mixed> $bindings Controller constructor arguments, by name
     * @param class-string<RequestInput>|null $inputClass The trailing input of the action
     * @param list<class-string> $middlewares The middlewares scoped to this route, outermost first
     * @param string|null $name The qualified name of the route (`module:name`), if it has one
     * @param string|null $module The module namespace
     * @param bool $localeExplicit Whether the routing imposes `$locale`. No effect when `$locale` is null
     */
    public function __construct(
        public string $controller,
        public string $action = RouterInterface::FALLBACK_ACTION,
        public array $params = [],
        public array $bindings = [],
        public ?string $name = null,
        public array $middlewares = [],
        public ?string $inputClass = null,
        public ?string $locale = null,
        public ?string $module = null,
        public bool $localeExplicit = true,
    ) {}

    /**
     * @param list<class-string> $middlewares
     */
    public function withMiddlewares(array $middlewares): self
    {
        return new self(
            controller: $this->controller,
            action: $this->action,
            params: $this->params,
            bindings: $this->bindings,
            name: $this->name,
            middlewares: $middlewares,
            inputClass: $this->inputClass,
            locale: $this->locale,
            module: $this->module,
            localeExplicit: $this->localeExplicit,
        );
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
}
