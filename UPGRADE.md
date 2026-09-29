# Upgrade guide

Kaly is `0.x`: breaking changes are made deliberately, in favor of a smaller and
sharper API, rather than piled up behind aliases. Each section lists what changed and
how to migrate.

## kaly-di 0.3

- `composer.json` now requires `lekoala/kaly-di: ^0.3`.
- Replacing a service is explicit: `set()` on an already defined id, or merging
  two definitions that own the same id, throws a `DefinitionException`. Use
  `rebind()` for intentional replacements (a `configure()` hook overriding a
  module service, a test swapping an implementation), with the optional
  `expected` guard as a compare-and-swap. A later module can no longer silently
  override an earlier binding: rebind from `whenAllLoaded()` or a hook instead.
- New `alias()` makes an id resolve to another entry (same shared instance).
- `Container::get()` rejects parameters configured for a constructor that does
  not declare them (`DefinitionException`): a typo'd parameter name now fails
  fast instead of being ignored.
- `Injector::make()` / `invoke()` validate the argument list (unknown named
  arguments, duplicates, surplus positionals): only pass what the callable
  declares. The dispatcher only forwards `request`/`ctx` to controllers whose
  constructor declares them.
- A union parameter with several available candidates is ambiguous and throws
  `UnresolvableParameterException`: pass the dependency explicitly.

## Dump helpers

- `d()` dumps through Symfony VarDumper's `dump()` (`var_dump()` fallback) and
  never stops the execution: use the new `dd()` to dump and exit. The homemade
  rendering is gone (argument names from source parsing, HTML/CLI branches,
  output buffering), VarDumper owns that job, including server mode.
- `DUMP_EXCEPTION` is removed: `d()` no longer throws a `ResponseException`.
- The `APP_DEBUG` guard stays: `d()` does nothing in production.
- VarDumper file links use `xdebug.file_link_format`
  (eg: `vscode://file/%f:%l`), the Kaly debug page keeps using
  `DUMP_IDE_PLACEHOLDER` (eg: `vscode://file/{file}:{line}:0`).

## PSR-15 only middlewares

- `GeneratorMiddleware` and `GeneratorMiddlewareInterface` are removed. Write plain
  PSR-15 middlewares: `try/finally` around `$handler->handle()` for metrics and cleanup,
  `try/commit/catch/rollback` for transactions.

## Request-scoped sessions

- `SessionFactoryInterface::create($request)` says where the session of a request comes
  from. `NativePhpSessionFactory` is the default; bind your own factory for concurrent
  runtimes. `HttpContext` takes it as a constructor dependency, the kernel passes it
  every cycle.
- `ArraySession` is concurrency-safe but persists nothing between requests: tests and
  isolated cycles only, never a production backend.

## Explicit controller results

- Controllers may return `Kaly\Http\JsonResponse::of($data, 201)` for JSON with a
  status code and headers (a plain array stays the shorthand for a 200 JSON response).
- `Kaly\View\View::of($template, $data)` builds a view; `withStatus(404)` answers with
  another status code. The dispatcher honors it when rendering.
- `AbstractController::redirectToRoute('shop:product', $params)` redirects to a named route
  (303 by default), generated for the request locale.
- `RequestDispatcher::prepareResponse()` now receives the `HttpContext` instead of the
  locale string.

## Slimmer response exceptions

- `ResponseException::svg()`, `::html()` and `::json()` are removed: shaped responses
  are controller results (`View`, `JsonResponse`), not exceptions. The class only
  carries a raw body (used by the debug dump).

## Immutable routes and contextual urls

- `Route` is now immutable and final: resolvers build it in one step with
  `$request->route($controller, $action, ...)` instead of `Route::to(...)` followed
  by property assignments. `Route::to()` is removed.
- `Route::$namespace`, `Route::$segments`, `Route::$definition` and `Route::toArray()`
  are removed. The qualified name is on `Route::$name`, the module namespace on
  `Route::$module`.
- `HttpContext::url()` and `HttpContext::urlFor()` generate for the request locale;
  `Router::url()` / `Router::urlFor()` keep their explicit locale for uses outside
  a request. Templates receive a `$url` generator (like `$i18n`): both names are
  reserved in view data.

## Route middlewares and request state

- Middlewares declared on routes and groups (`->middleware()` in a route table) are now
  **executed** by the framework, right before the controller. They were previously only
  stored. Check that every declared middleware is a real PSR-15 or generator middleware:
  an unknown class now fails at boot.
- New `#[Kaly\Router\Middleware(...)]` attribute on controllers and actions.
- Session and cookie changes made through `$ctx->session()` / `$ctx->cookies()` are now
  written to the response by the kernel. Remove any manual `addToResponse()` call, or
  cookies will be emitted twice.
- `NativePhpSession` built from a PSR-7 request no longer lets PHP send its own session
  cookie and cache headers: the cookie is on the PSR-7 response.
- `CookiePolicy` rejects a SameSite value other than `None`, `Lax` or `Strict`.

## One App, typed hooks, closure configs

### Entry point

```php
// before
$psr17Factory = new Nyholm\Psr7\Factory\Psr17Factory();
$creator = new Nyholm\Psr7Server\ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
ErrorHandler::handle(function () use ($creator) {
    $app = new App(dirname(__DIR__));
    $app->boot();
    $app->run($creator->fromGlobals());
});

// after
App::create(dirname(__DIR__))->run();
```

- `Kaly\Core\Application` is merged into the final `Kaly\Core\App`. Extending the app is
  no longer possible: use hooks and definitions.
- PSR-17 factories are discovered (Nyholm, Guzzle, Laminas, HttpSoft): remove the six
  `bind(...Factory::class, Psr17Factory::class)` lines. An explicit binding still wins.
- `run()` builds the request from the globals when called without one, and turns a boot
  failure into a 500. `handle()` and `run()` boot the app when needed.

### Hooks

| Before | After |
| --- | --- |
| `addCallback(App::CB_BEFORE_DEFINITIONS, $fn)` / `CB_AFTER_DEFINITIONS` | `configure($fn)` (runs after the modules, wins over the framework defaults) |
| `addCallback(App::CB_BOOTED, $fn)` | `onBoot($fn)` |
| `addCallback(App::CB_ERROR, $fn)` | `onError($fn)` |
| `addCallback(App::CB_AFTER_REQUEST, $fn)` | `onTerminate($fn)` |
| `addCallback(App::CB_BEFORE_REQUEST, $fn)` | an `incoming` middleware |
| `addCallback(App::CB_FINALIZE_RESPONSE, $fn)` | `middleware()->outgoing($fn, always: true)` |

`configure()`, `onBoot()` and `debug()` must be called before boot.

### Renamed accessors

| Before | After |
| --- | --- |
| `setDebug()` / `getDebug()` | `debug()` / `isDebug()` |
| `getBooted()` | `isBooted()` |
| `getBaseDir()`, `getPublicDir()`, `getTempDir()`, `getTempDirFor()`, `getModulesDir()`, `getResourcesDir()` | `paths()->base`, `paths()->publicDir()`, `paths()->temp()`, `paths()->tempFor()`, `paths()->modules()`, `paths()->resources()` |
| `App::FOLDER_*` constants | `Paths::MODULES`, `Paths::PUBLIC`, `Paths::TEMP`, `Paths::RESOURCES` |
| `getCache()`, `cachedData()`, `App::APP_CACHE` | removed: inject a `CacheInterface` |
| `getRequestHandler()` | removed: `getKernel()` |

### Module config.php

```php
// before
/** @var Kaly\Core\Module $this */
$this->setPriority(50);
$this->definitions()
    ->bind(Foo::class, Bar::class)
    ->lock();

// after
return static function (Module $module, Definitions $di): void {
    $module->priority(50);
    $di->bind(Foo::class, Bar::class);
};
```

- No `$this`, no manual `lock()`. A config using `$this` fails with an explicit message.
- `setPriority()` → `priority()`, `setNamespace()` → `namespace()`,
  `setDefinitionsCallback()` → `whenAllLoaded()`.

## Hierarchical routing

Every url now belongs to exactly one module, which resolves it alone, and everything a
module answers is declared in its `config.php`. There is no global route table anymore.

```text
/fr/boutique/panier/   locale -> entry point (claim > mount > default module) -> module resolvers
```

### routes.php and #[RouteAttribute] are gone

Declare the routes in `config.php`. **Paths are now relative to the module mount**:

```php
// before: modules/Shop/routes.php
return static function (Routes $routes): void {
    $routes->get('/shop/{slug}', [ShopController::class, 'show'])->name('shop.show');
    $routes->get('/about', [AboutController::class, 'index']);
};

// after: modules/Shop/config.php
return static function (Module $module, Definitions $di): void {
    $module->routes(function (Routes $routes): void {
        $routes->get('/{slug}', [ShopController::class, 'show'])->name('show');   // /shop/{slug}
    });
    // a path outside of the module segment is claimed explicitly
    $module->claim('/about', function (Routes $routes): void {
        $routes->get('/', [AboutController::class, 'index'])->name('about');
    });
};
```

- A `routes.php` file is ignored: move it into `config.php`.
- `#[RouteAttribute]` is removed: declare the route in the module table. Scanning every
  controller of every module at boot was the only boot cost growing with the size of
  the application.
- Paths that belong to the site root go in the default module (namespace `App`).

### Route names are qualified by module

```php
// before
$router->generate('shop.show', ['slug' => 'books']);
$router->generate([CartController::class, 'add']);

// after
$router->url('shop:show', ['slug' => 'books']);   // the default module may omit its prefix
$router->urlFor([CartController::class, 'add']);
```

- `RouterInterface::generate()` is replaced by `url()` (named routes) and `urlFor()`
  (conventional urls). The locale is an explicit argument, not a `locale` param.
- The `RouterInterface::MODULE`, `CONTROLLER`, `ACTION`... constants are removed.

### Locales belong to the app, the prefix to the module

```php
// before, in any module config
->callback(ClassRouter::class, fn(ClassRouter $r) => $r->setAllowedLocales(['en', 'fr'], ['LangModule']))

// after
APP_LOCALES=en,fr                     // or $app->locales(['en', 'fr'])
$module->localized();                 // in the config.php of LangModule itself
```

- Modules are **not localized by default**: a locale prefix on them redirects to the url
  without it. Call `localized()` on every module whose urls carry the locale.
- A module can have one segment per locale: `$module->mount(['fr' => 'boutique', 'en' => 'shop'])`,
  and a route one path per locale: `$routes->get(['fr' => '/a-propos', 'en' => '/about'], ...)`.
  Both require `localized()` on the module, and `localized()` requires the application
  to declare locales: anything else fails at boot (mounts, claim prefixes) or when the
  table compiles (paths).
- Generating an url for a locale the route or the mount has no variant for fails instead
  of producing an url no route would match.
- Every `$module->routes()` call feeds the same table: use the priority of each route
  (`->priority(10)`) instead of the removed second argument of `routes()`. A name shared
  by two declarations of the table fails when it compiles; a name shared with a claim
  fails when the url is generated.
- A 405 from the route table is authoritative: custom resolvers and the convention never
  reinterpret a path the table knows for other methods only.
- The redirect adding the default locale now builds an absolute path (`/en/...`).

### Removed classes

`ClassRouter`, `CompositeRouter`, `RouteCollectionRouter`, `AttributeRouteLoader`,
`RouteAttribute` and `AmbiguousRouteException` are removed. Their roles are held by
`Router` (entry points, locales, generation), `ConventionResolver`, `TableResolver`
and `ResolverInterface` for custom resolvers (`$module->resolver(...)`).

### Convention routing

- Every module is routable by convention under its decamelized name, without any
  configuration. **Review modules that were not exposed before**: opt out with
  `$module->withoutConventionRouting()`, or choose the segment with `$module->mount()`.
- A url that the module of its segment cannot resolve is a 404: it never falls back to
  another module.
- `Route::$module` is the module namespace (`TestVendor\MappedModule`).
- `Route::$name` holds the qualified name (`shop:show`), `Route::$bindings` the objects
  a resolver hands to the controller constructor.

## Error responses

- `ExceptionHandlerInterface::toResponse(Throwable $exception, ?ServerRequestInterface $request = null)`
  receives the request: update custom handlers.
- `RouteNotFoundException` is now a `NotFoundException` (an HTTP exception): a 404 is
  no longer logged nor reported to `onError()`, and its body is `Not Found` instead of
  `Server error`.
- A resolver may throw `RouteNotFoundException` to say why it does not know an url;
  `ConventionResolver::resolve()` does so and never returns `null`.
- JSON clients get `application/problem+json` error responses. Plain responses now
  carry a `Content-Type` (`text/plain; charset=utf-8`, or HTML for the debug page).
- `ExceptionHandler` takes a `debug` flag, set from the app debug mode. It no longer
  uses `ErrorHandler::generateError()`, which only renders errors outside of a request
  cycle (a failing boot).
- Failing `config.php` files and invalid route tables are wrapped in an `Ex` naming the
  module, with the original exception as previous.
