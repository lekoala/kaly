# Explicit routes

> `routes.php` and `#[Route]` produce exactly the same `RouteDefinition`

A route is a composition decision of the application, not a property of the
controller. Explicit routes are declared in per-module `routes.php` files:

```php
// modules/Shop/routes.php

use Kaly\Router\Routes;
use Shop\Controller\ShopController;

return static function (Routes $routes): void {
    $routes->get('/shop/{slug}', [ShopController::class, 'show'])
        ->name('shop.show')
        ->where('slug', '[a-z-]+');
};
```

Opening `routes.php` shows the whole public HTTP surface of the module.

## The model

Three distinct notions:

- `RouteDefinition` — a declaration (path, handler, methods, name,
  requirements, defaults, middlewares, priority).
- `Route` — the resolved result for one request, with its effective
  middlewares (`$route->middlewares`). A `Route` matched from the explicit
  table keeps a reference to its definition (`$route->definition`);
  conventional matches leave it `null`.
- `ClassRouter` — the purely conventional fallback, with no knowledge of
  declarations or attributes.

`CompositeRouter` tries the explicit collection first, then the convention.
For plain CRUD no declaration is needed; only the exception gets declared.

Precedence rules, enforced at boot wherever possible:

- **Collisions fail fast.** Two definitions with an equivalent match space,
  overlapping methods and the same priority throw at boot. Equivalence is
  textual on the canonical pattern (placeholder names erased, effective
  fragments compared): `/users/{id}` and `/users/{slug}` collide, while
  differing requirements stay distinct and resolve by specificity. Different
  priorities are an explicit precedence choice and stay allowed; disjoint
  methods (GET vs POST) never collide.
- **Names are absolute.** A name identifies exactly one definition, whatever
  its path or priority — duplicates throw at boot, since `generate(name)`
  must never depend on matcher order.
- **An explicit 405 is authoritative.** A declared path claims its match
  space: `POST /foo` declared + `GET /foo` requested is a 405, even if the
  convention would match something else. Only a 404 reaches the fallback.
- **Ambiguous handlers fail at generate time.** One handler with several
  urls has no canonical url: `generate([Foo::class, 'bar'])` throws and the
  caller must use a name. The error is never masked by the conventional
  fallback.
- **Defaults are generate-only.** There are no optional path segments: every
  placeholder matches literally (failed coercion means no match, as with
  `ClassRouter`). A missing param with a default resolves at generation;
  without one, generation fails.

## The DSL

```php
$routes->get($path, $handler);
$routes->post($path, $handler);
$routes->put($path, $handler);
$routes->patch($path, $handler);
$routes->delete($path, $handler);
$routes->map(['GET', 'HEAD'], $path, $handler);
```

Per-route configuration:

```php
$route
    ->name('shop.show')
    ->where('id', '\d+')
    ->middleware(AuthMiddleware::class)
    ->default('tab', 'info')
    ->priority(10);
```

Structural composition — groups, prefixes, shared middlewares:

```php
$routes->group('/api', function (Routes $routes): void {
    $routes->get('/health', [ShopController::class, 'health']);
});

$routes->prefix('/staff')->middleware(StaffMiddleware::class)->group(function (Routes $routes): void {
    $routes->get('/agenda', [AgendaController::class, 'index']);
});
```

Handlers are `[Controller::class, 'action']`, `Controller::class` (for
`__invoke`) or `'Controller::action'`. Invokables keep handlers almost
framework-independent:

```php
$routes->get('/patients/{id}', ShowPatient::class);
```

A mapping never makes a method executable: the handler must already be an
admissible action (public, non-static, non-magic except `__invoke`), enforced
at build time with a clear message.

## `#[Route]`: local sugar for one route

```php
use Kaly\Router\RouteAttribute;

#[RouteAttribute('/shop/{slug}', methods: ['GET'], name: 'shop.show')]
public function show(string $slug): View
```

This compiles to exactly the same `RouteDefinition` the DSL above produces
(there is a test locking that equivalence). Removing the attribute removes
the alias; the conventional route stays valid.

Rule of thumb:

> **Attributes describe one route. The route DSL composes routes.**

Structural concerns — groups, prefixes, shared middlewares — have no
attribute equivalent on purpose. A method may carry several `#[Route]`
attributes (eg: `/foo` and `/legacy/foo`); each compiles to its own
definition, which is exactly why `generate(handler)` refuses ambiguity while
`generate(name)` never has any.

Once compiled into a `RouteCollection`, nothing mutates implicitly:
`RouteDefinition` is readonly and the collection exposes value snapshots.
`RouteCollection::toArray()` returns the compiled table in matching order
for inspection and debugging.

## Route middlewares and access

A route, a group or a controller may carry middlewares. The framework runs
them right before the controller, at the end of the routed band:

```text
incoming -> routing -> routed -> [route middlewares] -> dispatcher
```

```php
$routes->post('/patients/{id}/edit', [PatientController::class, 'edit'])
    ->middleware(Authenticated::class);
```

`#[Middleware]` declares them on the controller itself. It applies however
the action is reached (convention, `routes.php` or `#[RouteAttribute]`), and a
class attribute covers every subclass, so a base controller can protect a
whole area:

```php
use Kaly\Router\Middleware;

#[Middleware(StaffOnly::class)]
abstract class AdminController extends AbstractController {}

final class PatientController extends AdminController
{
    #[Middleware(AuditTrail::class)]
    public function delete(int $id): ResponseInterface
}
```

Rules:

- **Order is outermost first**: `routes.php` groups and route, then parent
  classes, the class, then the method. A middleware declared at several
  levels runs once, at its outermost place.
- **A declared middleware always runs, or fails loudly.** An unknown class,
  or a class that is not a PSR-15 / generator middleware, throws at boot for
  explicit routes (at first match for convention routes). An ignored auth
  middleware would be an open door.
- The effective list is `$ctx->route()->middlewares`, and each one that
  entered is traced on the context like any other middleware.

Kaly core stays generic here (route middlewares yes, permission system no):
a future auth adapter gives semantics to names like `Requires`. Keep the
layers apart: the router decides what is exposable, a routed middleware
decides whether the actor may enter, and the use case or domain policy
decides whether the actor may operate on that specific object — so CLI or
internal callers cannot bypass HTTP-level checks.

## How it is wired

Each `modules/*/routes.php` returns a `function (Routes $routes): void`
closure, loaded by `Module` at boot; `#[Route]` attributes found in
`src/Controller/` compile to the same definitions through the tiny
`AttributeRouteLoader` (FQCN derived from the file path, no scanner, no
Composer plugin). Everything merges into one `RouteCollection`, matched by
`RouteCollectionRouter` before `ClassRouter` runs.
