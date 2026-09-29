# Modules

> How an application organizes its own code

## What a module is (and is not)

A module is a **unit of organization of your application**, not a unit of distribution.

```text
Composer packages      classes and interfaces are available
        |
        v
Definitions            the application composes them explicitly
        |
        v
Modules                the application organizes its own code
```

Composer handles distribution and autoloading. `Definitions` handles composition.
Modules structure the app. Kaly never scans installed packages for `extra` keys and
never gives a dependency behaviour just because it was installed: a library becomes
part of the app when a `config.php` binds it.

## Layout

Every folder of `modules/` containing a `config.php` is a module. `config.php` is the
only required file — it may be empty — and the only file booted per module: services
and routing are both declared there.

```text
modules/
  Common/
    config.php
    src/
  Site/
    config.php
    src/
    templates/
  Admin/
    config.php
    src/
    templates/
  Api/
    config.php
    src/
```

| Path         | Role                                                          |
| ------------ | ------------------------------------------------------------- |
| `config.php` | local composition root, required                              |
| `src/`       | the classes, under the module namespace                        |
| `templates/` | registered under the module name (see [Views](views.md))       |
| `assets/`    | browser-ready static files of the module (see [Assets](assets.md)) |

The namespace defaults to the camelized folder name (`routable-module` →
`RoutableModule`). Use `$module->namespace('Vendor\Thing')` to override it.

## A typical layering

```text
        Common
      /   |    \
   Site  Admin  Api
```

`Common` holds what is genuinely transversal: domain model, repository interfaces,
shared services, value objects. Avoid cycles (`Site -> Admin -> Api -> Site`) and
avoid turning `Common` into a giant `Utils`: share the domain when it is really
common, let the workflows live in the module that owns them.

```text
Common/Patient/Patient.php
Common/Patient/PatientRepository.php
Site/Appointment/PublicBookingService.php
Admin/Appointment/AppointmentManagementService.php
Api/Appointment/AppointmentController.php
```

Nothing enforces this: module dependencies are a convention, not a constraint.

## config.php

`config.php` returns a closure. It receives the module and its
definitions; the file runs in an empty scope, so nothing leaks, and the definitions are
locked by the framework once it returns.

```php
<?php

use Kaly\Core\Module;
use Kaly\Di\Definitions;

return static function (Module $module, Definitions $di): void {
    $di
        ->bind(PatientRepository::class, SqlPatientRepository::class)
        ->set(ApiClient::class, fn(ContainerInterface $c) => new ApiClient($c->get('apiUrl')));
};
```

The module side of the configuration is fluent:

| Method | Effect |
| --- | --- |
| `priority(50)` | configuration order, see below |
| `namespace('Vendor\Thing')` | root namespace of the module classes |
| `mount('boutique')` | url segment of the conventional routes |
| `mount(['fr' => 'boutique', 'en' => 'shop'])` | one url segment per locale |
| `localized()` | its urls carry the locale prefix |
| `routes(fn(Routes $routes) => ...)` | local route table, see [Routing](routing.md) |
| `resolver(PageResolver::class, priority: 500)` | custom resolver (pages in a database...) |
| `claim('/about', fn(Routes $routes) => ...)` | own a path outside of the module segment |
| `withoutConventionRouting()` | only expose the route tables and custom resolvers |
| `whenAllLoaded(fn(Definitions $all) => ...)` | second pass, see below |

The former format, where `config.php` used an implicit `$this`, is refused with an
explicit message.

A module config should be **fast, declarative and side effect free**: no I/O, no
database connection, no request dependent work, no expensive instantiation. Prefer a
factory over an eager instance, so that the cost stays dormant:

```php
// avoid: built during boot, on every request of every route
$di->set(HugeClient::class, new HugeClient(...));

// prefer: built on first get()
$di->set(HugeClient::class, fn() => new HugeClient(...));
```

## Loading order

Modules are discovered by sorted folder name, so the order is deterministic across
filesystems. Each one gets a priority of 100, 200, 300... in that order unless it sets
its own with `$module->priority(50)`. Definitions are then merged from the lowest
priority to the highest, so a later module can override an earlier binding. The
`App::configure()` hooks run last and win over every module.

A second pass runs after every module has been merged, for features that depend on
what the other modules declared:

```php
$module->whenAllLoaded(function (Definitions $all): void {
    if ($all->has(SearchInterface::class)) {
        $all->bind(IndexerInterface::class, SearchIndexer::class);
    }
});
```

## Autoloading

Declare your modules in `composer.json` — this is the recommended setup:

```json
{
  "autoload": {
    "psr-4": {
      "Common\\": "modules/Common/src/",
      "Admin\\": "modules/Admin/src/"
    }
  }
}
```

If a module `src/` directory is not covered by a psr-4 entry, Kaly registers a small
fallback autoloader for that module only (`Namespace\Some\Class` →
`modules/Name/src/Some/Class.php`). It is a convenience, not a discovery mechanism.

## Routing a module

Every url belongs to exactly one module, which resolves it alone. Every module is
mounted under its decamelized folder name, with no configuration: `modules/Admin`
answers on `/admin/...`, `modules/routable-module` on `/routable-module/...`. The module
whose namespace is `App` is the default one and answers without prefix.

```php
return static function (Module $module): void {
    $module
        ->mount('back-office')              // /back-office/... instead of /admin/...
        ->routes(function (Routes $routes): void {
            $routes->get('/orders/{id}', [OrderController::class, 'show'])->name('order');
        });
};
```

A url segment is mounted once: two modules mounting the same segment fail at boot.
See [Routing](routing.md) for resolvers, claims, locales and url generation.

## Registration is eager, resolution is lazy

Every `config.php` runs during `boot()`, so every module is always registered. But the
container is lazy: a service is only built on the first `get()`. Nothing decides which
modules are "active" — the dependency graph does it naturally.

```text
boot
 |- Common/config.php  --+
 |- Site/config.php      |  declarations only
 |- Admin/config.php     |
 +- Api/config.php     --+

GET /api/patients
        |
    route Api
        |
  Api\PatientController
        |- PatientRepository
        +- ApiSerializer      <- the only services actually built

Admin and Site services: never requested
```

## Route aware behaviour

Behaviour that belongs to a module rather than to a service goes in the routed
middleware band, where the route — and therefore the module — is already known:

```php
$app->middleware()->routed(
    AdminAuthorization::class,
    when: static fn(HttpContext $ctx): bool => $ctx->route()->module === 'Admin',
);
```

The condition is evaluated before the middleware is resolved, so a skipped middleware
is never even built. Use this for admin audit and session policy, API CORS and
authentication, site locale and CSP...

What applies to the *response* — regardless of the module or the origin of the
response — goes in the outgoing band instead:

```php
$app->middleware()->outgoing(
    ApiSerializer::class,
    when: static fn(ResponseInterface $response): bool => $response->getHeaderLine('Content-Type') === 'application/json',
);
```

Do **not** try to mutate the container per module (`$ctx->activateModule('Api')`). The
container is application scoped while the context is request scoped: in a worker,
requests hit `Api`, then `Admin`, then `Site` on the same container, and mutating the
composition per route would break singletons already resolved.

> **Modules are always registered, but their runtime dependencies are demand driven.
> Route specific behaviour belongs after routing, not in module registration.**
