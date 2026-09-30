<?php

declare(strict_types=1);

namespace Kaly\Router;

use Closure;

/**
 * A routes() declaration: one of the closures feeding the route table of a
 * scope. Kept distinct from resolvers so a routing declaration never has to
 * be told apart from a resolver by sniffing callables.
 *
 * @internal
 */
final class RoutesDeclaration
{
    /**
     * @param Closure(Routes): void $declare
     */
    public function __construct(
        public readonly Closure $declare,
    ) {}
}
