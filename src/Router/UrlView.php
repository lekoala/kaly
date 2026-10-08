<?php

declare(strict_types=1);

namespace Kaly\Router;

/**
 * The url generator of a render, bound to the locale of that render.
 *
 * It is a projection of the router for one render: the locale is captured when
 * the render environment is built, so a later locale change on the request
 * context cannot rewrite the urls a template already resolved.
 */
final readonly class UrlView
{
    public function __construct(
        private RouterInterface $router,
        private string $locale,
    ) {}

    /**
     * @param array<string,mixed> $params
     */
    public function __invoke(string $name, array $params = []): string
    {
        return $this->router->url($name, $params, $this->locale);
    }
}
