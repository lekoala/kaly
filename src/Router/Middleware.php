<?php

declare(strict_types=1);

namespace Kaly\Router;

use Attribute;

/**
 * Scopes PSR-15 middlewares to a controller or to one of its actions.
 *
 * Declared on a class or on a method, and lists one or more middlewares in a
 * single instance:
 *
 * ```php
 * #[Middleware(
 *     AuthMiddleware::class,
 *     CsrfMiddleware::class,
 * )]
 * final class Controller
 * {
 *     #[Middleware(AdminMiddleware::class)]
 *     public function action(): ResponseInterface {}
 * }
 * ```
 *
 * It applies whatever the way the action was reached: by convention or
 * through a route table. On a class it also covers every subclass, so a base
 * controller can protect a whole area:
 *
 * ```php
 * #[Middleware(StaffOnly::class)]
 * abstract class AdminController extends AbstractController {}
 * ```
 *
 * Route middlewares run at the end of the routed band, right before the
 * controller, outermost first: route table groups, then parent classes, then
 * the class, then the method.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Middleware
{
    /**
     * @var list<class-string>
     */
    public readonly array $middlewares;

    /**
     * @param class-string ...$middlewares
     */
    public function __construct(string ...$middlewares)
    {
        $this->middlewares = array_values($middlewares);
    }
}
