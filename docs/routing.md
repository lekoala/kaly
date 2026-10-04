---
layout: default
title: Routing
nav_order: 6
---
# Routing

> Every url belongs to exactly one module, which resolves it alone

To know how `/fr/boutique/...` behaves, open `modules/Shop/config.php`: that is where
the module declares its mount, its routes, its resolvers and whether it is localized.
There is no global route file.

## How a url is resolved

```text
/fr/boutique/panier/ajouter/42
 │    │         └── resolved by the Shop module, and the Shop module only
 │    └── entry point: claim (longest prefix) > mount > default module
 └── locale, when the application declares locales
```

1. **Locale.** When the application declares locales (`APP_LOCALES=fr,en` or
   `$app->locales(['fr', 'en'])`), a leading locale segment is consumed. The first
   locale is the default one.
2. **Entry point.** A claimed prefix wins (the longest one first). Otherwise the first
   segment is looked up among the module mounts. Otherwise the url belongs to the
   default module, the one mounted on `/`, which answers without prefix.
3. **Module resolvers.** The module resolves what is left with its resolvers, by
   priority (lowest first, then declaration order). The first one returning a route
   wins; one returning `null` hands over to the next. Nothing else is looked at: a
   module never sees the urls of another one.

Resolving a request therefore costs one lookup plus the resolvers of a single module,
whatever the size of the application.

## Mounts

Every module is mounted under its decamelized folder name (`modules/Shop` answers on
`/shop/...`). A module chooses its segment, one per locale if it wants:

```php
return static function (Module $module): void {
    $module->mount('boutique');
    // or
    $module->mount(['fr' => 'boutique', 'en' => 'shop'])->localized();
    // or own the root (a single module may do so)
    $module->mount('/');
};
```

A segment is mounted once: two modules claiming it fail at boot, and two modules
mounting `/` fail at boot. A non canonical
spelling (`/Shop/`, `/SHOP/`) redirects to `/shop/`.

## Resolvers

| Resolver | Priority | Declared with |
| --- | --- | --- |
| route table | 0 | `$module->routes(fn(Routes $routes) => ...)`, once or several times: every call feeds the same table |
| custom resolver | 0 | `$module->resolver(PageResolver::class, priority: 500)` |
| convention | 1000 | implicit, removed with `$module->withoutConventionRouting()` |

### The route table

Paths are relative to the module entry point: in a module mounted on `shop`, `/cart`
answers on `/shop/cart/`.

```php
$module->routes(function (Routes $routes): void {
    $routes->get('/produit/{slug}', [ProductController::class, 'show'])
        ->name('product')
        ->where('slug', '[a-z-]+');

    // One path per locale
    $routes->get(['fr' => '/a-propos', 'en' => '/about'], [ContactController::class, 'index'])->name('contact');

    $routes->prefix('/admin')->middleware(StaffOnly::class)->group(function (Routes $routes): void {
        $routes->get('/orders', [OrderController::class, 'index']);
    });
});
```

```php
$routes->get($path, $handler);   // post, put, patch, delete
$routes->map(['GET', 'HEAD'], $path, $handler);

$route->name('product')->where('id', '\d+')->middleware(Auth::class)->default('tab', 'info')->priority(10);
```

Handlers are `[Controller::class, 'action']`, `Controller::class` (for `__invoke`) or
`'Controller::action'`. A declaration never makes a method executable: it must be an
admissible action (public, non-static, non-magic except `__invoke`).

Rules, checked when the table is compiled (on its first use, at match or
generation time — only mount and claim conflicts fail at boot):

- **Collisions fail.** Two routes with an equivalent path (placeholder names erased),
  overlapping methods and the same priority throw. Different priorities are an
  explicit choice.
- **Names are unique** across the table and the claims of the module: a
  duplicate within the table fails at compile time, a name shared with a claim
  fails when the url is generated.
- **A 405 is authoritative.** A path the table knows for other methods only is a
  `405`: custom resolvers and the convention never get a chance to reinterpret it.
- **Defaults are generate-only.** Every placeholder matches literally; a default only
  fills a missing parameter when generating an url.

The table is compiled on its first use and kept in memory: a request only pays for
the module it reaches, and a worker pays once. Kaly never writes a route cache.

### The convention

The last resolver of every module maps `controller/action/params`:

```text
/shop/                  Shop\Controller\IndexController::index()
/shop/cart/             Shop\Controller\CartController::index()
/shop/cart/add/42/      Shop\Controller\CartController::add(42)
```

- The action is the camelized segment. An action whose name ends with an HTTP verb
  (`addPost`, `removeDelete`) is restricted to that verb: `GET /cart/add-post/` is a
  `405` with an `Allow: POST` header, and a bare name answers any method. Recognized
  suffixes: `Get`, `Post`, `Put`, `Patch`, `Delete`, `Head`, `Options`.
- Remaining segments are the action parameters, and only url segments. They are
  coerced strictly: a value that does not fit an `int`/`float`/`bool` parameter does
  not match (404). A variadic `...$rest` takes every remaining segment; extra
  segments otherwise do not match.
- One trailing `Kaly\Http\Input\RequestInput` parameter is built from the query string and
  the body, and consumes no segment (see [Request input](input.md)). Any other
  object parameter is refused: services belong to the constructor.
- One canonical url per action: `/index/`, `/cart/index/` and camelized spellings
  redirect. `/index/index/param/` is allowed.
- Public methods only; magic methods other than `__invoke` are never exposed.

### A custom resolver

A resolver decides at runtime whether it knows the url, like pages stored in a
database (SilverStripe's `ModelAsController`):

```php
final class PageResolver implements ResolverInterface
{
    public function __construct(private PageRepository $pages) {}

    public function resolve(RouteRequest $request): ?Route
    {
        $page = $this->pages->findByPath($request->segments);
        if ($page === null) {
            return null;    // not mine: the next resolver tries
        }
        // The page reaches the controller constructor: PageController(Page $page)
        return $request->route($page->controllerClass(), 'index', bindings: ['page' => $page]);
    }
}

$module->resolver(PageResolver::class, priority: 500); // after the table, before the convention
```

`RouteRequest` carries the remaining `segments`, the consumed `prefix`, the module
namespace and the `locale`. A resolver given as a class is resolved from the
container: it is a shared service, keep it free of per-request state.

## Claims

A module can own a path outside of its segment, with its own table. Paths of the
table are relative to the claimed prefix:

```php
$module->claim(['fr' => '/actualites', 'en' => '/news'], function (Routes $routes): void {
    $routes->get('/', [NewsController::class, 'index'])->name('news');
});
```

Claims are explicit on purpose, and checked at boot: two claims on the same prefix,
or a claim inside the segment of another module, fail. The root cannot be claimed:
it belongs to the module mounted on `/`, declare its routes there.

A claim is an **island with its own table**, not a second mount: below the claimed
prefix the module resolves with that table only. Its custom resolvers and its
convention do not run there. Declaring the same routes again through
`$module->routes()` covers both the mounted and the claimed urls in one place, so
this only matters when a module wants a genuinely different table for a path it
does not own.

## Locales

The locales belong to the application, the locale prefix to the module:

```php
$module->localized();   // its urls carry the locale: /fr/boutique/, /en/shop/
```

- A localized module requires its locale: `/boutique/` redirects to `/fr/boutique/`
  (the home page `/` is the exception). This holds with a single application
  locale too.
- A module that is not localized refuses it: `/fr/api/` redirects to `/api/`.
- Locales stay coherent: a mount, a claim prefix or route paths per locale
  require `localized()`, and `localized()` requires the application to declare
  locales. Anything else fails at boot (mounts, prefixes) or when the table
  compiles (paths).
- `/fr/` alone, with `fr` the default locale, redirects to `/`.
- The locale prefix is lowercase: `/FR/boutique/` redirects to `/fr/boutique/`.
- The locale of the route wins over the one of a middleware and over the
  `Accept-Language` negotiation (see [i18n](i18n.md)).

## Generating urls

Route names are local to their module and qualified from the outside:

```php
$router->url('shop:product', ['slug' => 'velo']);          // /fr/boutique/produit/velo/
$router->url('shop:product', ['slug' => 'bike'], 'en');    // /en/shop/product/bike/
$router->url('contact');                                   // the default module may omit its prefix
$router->url('shop:product', ['slug' => 'velo', 'ref' => 'home']); // extra params become the query string

$router->urlFor([CartController::class, 'add'], [42]);    // conventional url: /fr/boutique/cart/add/42/
```

Without a locale, urls are generated for the default locale. Generating an url
for a locale the route (or the module mount) has no variant for fails instead
of producing an url no route would match.

Within a request, the context generates for the request locale — no need to
pass it around:

```php
$ctx->url('shop:product', ['slug' => 'velo']);   // /en/... when the request runs in English
$ctx->urlFor([CartController::class, 'add'], [42]);
```

A controller redirects with `$this->redirectToRoute('shop:product', ['slug' => $slug])`
(303 by default), generated for the request locale just like `$ctx->url()`.

Templates get the same generator as `$url`, alongside the `$i18n` translator
(both are reserved: a view datum under either name is overridden):

```html
<a href="<?= $url('shop:product', ['slug' => $slug]) ?>">...</a>
```

## Route middlewares

A route, a group or a controller may carry middlewares. The framework runs them right
before the controller, at the end of the routed band:

```text
incoming -> routing -> routed -> [route middlewares] -> dispatcher
```

`#[Middleware]` declares them on the controller itself. It applies however the action
is reached (table, convention or custom resolver), and a class attribute covers every
subclass, so a base controller can protect a whole area:

```php
use Kaly\Router\Middleware;

#[Middleware(StaffOnly::class)]
abstract class AdminController extends AbstractController {}

final class OrderController extends AdminController
{
    #[Middleware(AuditTrail::class)]
    public function delete(int $id): ResponseInterface
}
```

- **Order is outermost first**: table groups and route, then parent classes, the
  class, then the method. A middleware declared at several levels runs once, at its
  outermost place.
- **A declared middleware always runs, or fails loudly.** An unknown class, or a
  class that is not a PSR-15 middleware, throws when the table is
  compiled (on its first use, at match or generation time; at first match for
  the convention). An ignored auth middleware would be
  an open door.
- The effective list is `$ctx->route()->middlewares`, and each one that entered is
  traced on the context like any other middleware.

Keep the layers apart: the router decides what is exposable, a routed middleware
decides whether the actor may enter, and the use case or domain policy decides whether
the actor may operate on that specific object — so CLI or internal callers cannot
bypass HTTP-level checks.

## Trailing slash

Urls end with a slash: `/shop/cart` redirects to `/shop/cart/`.

