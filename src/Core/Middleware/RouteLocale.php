<?php

declare(strict_types=1);

namespace Kaly\Core\Middleware;

use Kaly\Core\HttpContext;
use Kaly\Ex;
use Kaly\Http\Exception\NotFoundException;
use Kaly\I18n\Locale;
use Kaly\I18n\LocaleResolver;
use Kaly\Router\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;

/**
 * Projects a route placeholder (eg `{locale}`) onto the request locale.
 *
 * ```text
 * action argument 'locale'
 *         ↓
 * validation against the allowed locales
 *         ↓
 * HttpContext::useLocale()
 * ```
 *
 * The placeholder stays an ordinary action argument: nothing is reserved
 * globally. A supported value applies, an unsupported one is a 404, and a
 * missing argument or a contradiction with `Route::locale` is a developer
 * error (`Kaly\Ex`).
 */
final readonly class RouteLocale implements MiddlewareInterface
{
    public function __construct(
        private LocaleResolver $resolver,
        private string $param = 'locale',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ctx = HttpContext::from($request);

        if (!$ctx->hasRoute()) {
            throw new Ex('RouteLocale must run after routing, in the routed band or on a route');
        }

        $route = $ctx->route();
        $value = $this->paramValue($route, $this->param);

        if (!is_string($value) || $value === '') {
            throw new Ex("Route argument '{$this->param}' must be a non-empty string for RouteLocale");
        }

        $locale = strtolower($value);
        $allowed = $this->resolver->getAllowedLocales();

        if (!Locale::isValid($locale) || $allowed !== [] && !in_array($locale, $allowed, true)) {
            throw new NotFoundException("Unsupported locale '{$value}'");
        }

        if ($route->locale !== null && $route->localeExplicit && $route->locale !== $locale) {
            throw new Ex("Route locale '{$route->locale}' contradicts '{$this->param}' argument '{$locale}'");
        }

        $ctx->useLocale($locale);

        return $handler->handle($request);
    }

    /**
     * The value of a named action argument: route params are positional, so
     * the position comes from the action signature.
     *
     * @throws Ex When the action declares no such argument
     */
    private function paramValue(Route $route, string $name): mixed
    {
        if (!class_exists($route->controller)) {
            throw new Ex("Route controller '{$route->controller}' does not exist for RouteLocale");
        }
        $reflection = new ReflectionClass($route->controller);

        if (!$reflection->hasMethod($route->action)) {
            throw new Ex("Route action '{$route->action}' does not exist for RouteLocale");
        }

        $actionParams = $reflection->getMethod($route->action)->getParameters();
        if ($route->inputClass !== null && $actionParams !== []) {
            array_pop($actionParams);
        }

        $index = 0;
        foreach ($actionParams as $actionParam) {
            if ($actionParam->isVariadic()) {
                throw new Ex("Route argument '{$name}' cannot be resolved on the variadic action '{$route->action}'");
            }
            if ($actionParam->getName() === $name) {
                if (!array_key_exists($index, $route->params)) {
                    throw new Ex("Route '{$route->name}' has no '{$name}' argument for RouteLocale");
                }
                return $route->params[$index];
            }
            $index++;
        }

        throw new Ex("Route '{$route->name}' has no '{$name}' argument for RouteLocale");
    }
}
