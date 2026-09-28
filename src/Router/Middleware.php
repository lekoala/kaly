<?php

declare(strict_types=1);

namespace Kaly\Router;

use Attribute;

/**
 * Scopes PSR-15 middlewares to a controller or to one of its actions.
 *
 * It applies whatever the way the action was reached: by convention, through
 * routes.php or through `#[RouteAttribute]`. On a class it also covers every
 * subclass, so a base controller can protect a whole area:
 *
 * ```php
 * #[Middleware(StaffOnly::class)]
 * abstract class AdminController extends AbstractController {}
 *
 * final class PatientController extends AdminController
 * {
 *     #[Middleware(AuditTrail::class)]
 *     public function delete(int $id): ResponseInterface
 * }
 * ```
 *
 * Route middlewares run at the end of the routed band, right before the
 * controller, outermost first: routes.php groups, then parent classes, then
 * the class, then the method.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
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
