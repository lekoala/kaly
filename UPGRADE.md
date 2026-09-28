# Upgrade guide

Kaly is `0.x`: breaking changes are made deliberately, in favor of a smaller and
sharper API, rather than piled up behind aliases. Each section lists what changed and
how to migrate.

## Route middlewares and request state

- Middlewares declared on routes and groups (`->middleware()` in `routes.php`) are now
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
    ->callback(ClassRouter::class, fn(ClassRouter $r) => $r->addAllowedNamespace('Shop'))
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

### Convention routing

- Every module is now routable by convention under its decamelized name, without any
  configuration. **Review modules that were not exposed before**: opt out with
  `$module->withoutConventionRouting()`, or choose the segment with `$module->mount()`.
- `ClassRouter::addAllowedNamespace()` / `setAllowedNamespaces()` / `getAllowedNamespaces()`
  are replaced by `mount(string $segment, string $namespace)` / `getMounts()`.
- `Route::$module` is the module namespace for both routers (`TestVendor\MappedModule`,
  not only its first segment).
- `CompositeRouter::setAllowedLocales()` now also configures the convention router.
