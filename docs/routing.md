---
layout: default
title: Routing
nav_order: 7
---
# Routing

> Every url belongs to exactly one module, which resolves it alone

To know how `/boutique/...` behaves, open `modules/Shop/config.php`: that is where
the module declares its mount, its routes, its resolvers and whether it is localized.
There is no global route file.

`APP_LOCALES` describes the i18n languages, never a url topology. Url prefixes
and the trailing-slash policy are configured explicitly, before boot:

```php
use Kaly\Router\TrailingSlash;

$app->routing(TrailingSlash::Preserve, localePrefixes: false);
```

The defaults are `Preserve` (no slash redirect) and no locale prefixes: the
framework canonicalizes nothing the application did not ask for. The
recommended profile is `App::default()` (`Remove` with locale prefixes); to
keep legacy urls, use `routing(TrailingSlash::Add, true)` explicitly.

## How a url is resolved

```text
/shop/cart/add/42
 �         ��� resolved by the Shop module, and the Shop module only
 ��� entry point: claim (longest prefix) > mount > default module
```

1. **Locale prefix (opt-in).** With `routing(localePrefixes: true)`, a leading
   locale segment is consumed for modules declaring `localized()`. The first
   locale is the default one. Without prefixes, every segment belongs to
   routes, mounts and claims — including a segment that happens to look like
   a locale.
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
`/shop/...`). A module chooses its segment; with locale prefixes enabled, one
per locale if it wants:

```php
return static function (Module $module): void {
    $module->mount('boutique');
    // or, with routing(localePrefixes: true):
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
- The convention never exposes an action owned by explicit routing: once an
  action appears in a route table, only its explicit urls answer (see
  [Route middlewares](#route-middlewares)).

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

The locales belong to the application, the locale prefix to an explicit router
strategy: `localized()` means the module participates in locale-prefix
routing, which additionally requires `routing(localePrefixes: true)`. A
`localized()` module with prefixes disabled (or without declared locales)
fails at boot.

With prefixes enabled:

```php
$module->localized();   // its urls carry the locale: /fr/boutique/, /en/shop/
```

- A localized module requires its locale: `/boutique/` redirects to `/fr/boutique/`
  (the home page `/` is the exception). This holds with a single application
  locale too.
- A module that is not localized refuses it: `/fr/api/` redirects to `/api/`.
- Locales stay coherent: with prefixes enabled, a mount, a claim prefix or route paths per locale
  require `localized()`, and `localized()` requires the application to declare
  locales. Anything else fails at boot (mounts, prefixes) or when the table
  compiles (paths).
- `/fr/` alone, with `fr` the default locale, redirects to `/`.
- The locale prefix is lowercase: `/FR/boutique/` redirects to `/fr/boutique/`.
- The locale of the route wins over the one of a middleware and over the
  `Accept-Language` negotiation (see [i18n](i18n.md)).

Without prefixes, translated paths stay usable wherever their full paths
distinguish the languages — no prefix is consumed or added:

```text
/medecins/    (fr)
/artsen/      (nl)
```

Each variant matches by path and reports its locale in `Route::locale`;
generation stays symmetric with matching. This is distinct from `localized()`:
translated paths describe routes that exist in several languages, while
`localized()` opts into the prefix strategy.

An application that designs its own `/{locale}/...` topology keeps prefixes
disabled and reads the placeholder with the explicit `RouteLocale` middleware
(Core, routed band), which projects the parameter onto the request locale
before the controller — a supported value applies, an unsupported one is a
404:

```php
use Kaly\Core\Middleware\RouteLocale;

$routes->get('/{locale}/consultations', [ConsultController::class, 'list'])
    ->middleware(RouteLocale::class);
```

The placeholder stays an ordinary action argument; `{locale}` is never
reserved globally.

## Generating urls

Route names are local to their module and qualified from the outside:

```php
$router->url('shop:product', ['slug' => 'velo']);          // /fr/boutique/produit/velo/
$router->url('shop:product', ['slug' => 'bike'], 'en');    // /en/shop/product/bike/
$router->url('contact');                                   // the default module may omit its prefix
$router->url('shop:product', ['slug' => 'velo', 'ref' => 'home']); // extra params become the query string

$router->urlFor([CartController::class, 'add'], [42]);    // conventional url: /fr/boutique/cart/add/42/
```

(Examples above with `routing(TrailingSlash::Add, localePrefixes: true)`; with
`Preserve` the same urls generate without a trailing slash, and without
prefixes no locale segment is added.)

Without a locale, urls are generated for the default locale. Generating an url
for a locale the route (or the module mount) has no variant for fails instead
of producing an url no route would match. `urlFor()` on an action owned by
explicit routing fails the same way and points at the route name, instead of
producing a conventional url no route answers.

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

A route, a group or a module may carry middlewares. The framework runs them right
before the controller, at the end of the routed band:

```text
incoming -> routing -> routed -> [route middlewares] -> dispatcher
```

```php
$routes
    ->prefix('/admin')
    ->middleware(StaffOnly::class)
    ->group(function (Routes $routes): void {
        $routes->get('/orders', [OrderController::class, 'index']);
        $routes->delete('/orders/{id}', [OrderController::class, 'delete'])
            ->middleware(AuditTrail::class);
    });
```

- **Order is outermost first**: module, then table groups and route. A middleware
  declared at several levels runs once, at its outermost place.
- **A declared middleware always runs, or fails loudly.** An unknown class, or a
  class that is not a PSR-15 middleware, throws when the table is
  compiled (on its first use, at match or generation time; at first match for
  the convention). An ignored auth middleware would be
  an open door.
- The effective list is `$ctx->route()->middlewares`, and each one that entered is
  traced on the context like any other middleware.

HTTP policy belongs to routing: a route exposes a controller **and** declares
the middlewares required to reach it. A policy that belongs to one route lives
on that route; a policy for a whole area lives on the module
(`$module->middleware(...)`), which covers its tables, its claims and its
convention. An action with its own policy deserves an explicit route.

**Explicit routing takes ownership of an action.** Once a controller action
appears in a route table, convention routing neither resolves nor generates a
url for it — a protected table url can never leak through a conventional one.
Ownership compares the canonical action identity, whatever letter case the
declaration used, and a refused conventional url is never reinterpreted
through another action or the index fallback.
`urlFor()` on an owned action fails and points at the route name instead of
guessing which explicit route was meant. Custom resolvers claim nothing
automatically: a dynamic resolver that changes the reachability or the policy
of controller actions belongs in a module without convention routing, or relies
on module-level middleware.
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

The policy is explicit, via `Kaly\Router\TrailingSlash`:

- `Add`: urls end with a slash, other spellings redirect (query string kept).
- `Remove`: urls carry no trailing slash, other spellings redirect. `/` never
  redirects.
- `Preserve` (default): both spellings match, nothing redirects; generation
  keeps the declared spelling (conventions generate without a slash, except
  the root). `Preserve` never declares `/foo` and `/foo/` as two different
  resources: such a collision fails when the table compiles.

In short: `App::create()` is neutral (`Preserve`), `App::default()` is the
recommended profile (`Remove` with locale prefixes), `routing(Add, true)` is
the explicit legacy choice.

`TrailingSlash` is a bidirectional contract, not just an inbound redirect
policy: `url()` and `urlFor()` always produce the canonical form of the
policy, so a generated url matches its route directly, without a
canonicalization redirect. `/` is the fixed point in every policy.

There is no file-like exception: with `Add`, an application route declared as
`/sitemap.xml` canonicalizes to `/sitemap.xml/`, like any other route.

## Migrating an existing site

Kaly canonicalizes only what the application configures (`App::routing()`):
trailing slashes, lowercase locale prefixes and the default-locale prefix
removal redirect when their policy is enabled, never otherwise. When
porting a site whose public urls must be preserved for search engines, pick
the policy closest to the legacy scheme and declare the remaining old urls
explicitly. An incoming middleware (running before routing) throws a
`RedirectException` for a legacy path:

```php
use Kaly\Http\Exception\RedirectException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LegacyRedirect implements MiddlewareInterface
{
    /** @param array<string, string> $map */
    public function __construct(private readonly array $map)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (isset($this->map[$path])) {
            throw new RedirectException($this->map[$path], RedirectException::MOVED_PERMANENTLY_REDIRECT);
        }

        return $handler->handle($request);
    }
}

$app->middleware()->incoming(new LegacyRedirect([
    '/ancien-chemin' => '/nouveau-chemin/',
    '/fr/tarifs' => '/tarifs/',
]));
```

A `301` is a permanent move, so the canonical url is the one indexed; prefer a
temporary `302` while the mapping is still being validated.



