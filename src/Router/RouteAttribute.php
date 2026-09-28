<?php

declare(strict_types=1);

namespace Kaly\Router;

use Attribute;

/**
 * Declares an explicit alias to an already admissible controller action.
 *
 * Local sugar for exactly one RouteDefinition — the same model routes.php
 * builds through the Routes DSL. Attributes describe one route; the DSL
 * composes routes (groups, prefixes, shared middlewares only exist there).
 *
 * An attribute never makes a method executable: it maps to an action that
 * is already admissible by normal controller rules (public, non-static,
 * non-magic except __invoke). Removing the attribute removes the alias;
 * the conventional route stays valid.
 *
 * ```php
 * #[RouteAttribute('/booking/{id}', methods: ['GET'], name: 'booking.show')]
 * public function show(int $id): View
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RouteAttribute
{
    /**
     * @param list<string> $methods HTTP methods, uppercase. Empty = any method.
     * @param array<string,string> $requirements Param name => PCRE fragment (without delimiters).
     * @param array<string,mixed> $defaults Default values for optional placeholders.
     * @param list<class-string> $middlewares Middlewares scoped to this route.
     */
    public function __construct(
        public string $path,
        public array $methods = ['GET'],
        public ?string $name = null,
        public int $priority = 0,
        public array $requirements = [],
        public array $defaults = [],
        public array $middlewares = [],
    ) {}
}
