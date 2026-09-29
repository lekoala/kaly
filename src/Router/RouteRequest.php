<?php

declare(strict_types=1);

namespace Kaly\Router;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What a module resolver receives: the part of the url below the entry point
 * of the module (its mount or one of its claims), once the locale is known.
 *
 * ```text
 * /fr/boutique/panier/ajouter/42
 *  locale  prefix     segments
 *  'fr'    '/fr/boutique'  ['panier', 'ajouter', '42']
 * ```
 */
final readonly class RouteRequest
{
    /**
     * @param list<string> $segments The path segments left to resolve
     * @param string $prefix The consumed part of the path (locale and entry point)
     * @param string $module The module namespace
     */
    public function __construct(
        public ServerRequestInterface $request,
        public array $segments,
        public string $prefix,
        public string $module,
        public ?string $locale = null,
    ) {}

    /**
     * The path left to resolve, always starting with a slash
     */
    public function path(): string
    {
        return '/' . implode('/', $this->segments);
    }

    public function method(): string
    {
        return strtoupper($this->request->getMethod());
    }
}
