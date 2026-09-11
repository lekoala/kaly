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
only required file — it may be empty.

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
| `assets/`    | static files of the module                                     |

The namespace defaults to the camelized folder name (`routable-module` →
`RoutableModule`). Use `$this->setNamespace('Vendor\Thing')` to override it.

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

Inside `config.php`, `$this` is the `Kaly\Core\Module`. In debug mode the
`/** @var Kaly\Core\Module $this */` header is prepended automatically for IDE support.
Local variables do not leak, and the definitions must be locked at the end.

```php
<?php

/** @var Kaly\Core\Module $this */

use Kaly\Router\ClassRouter;

$this->definitions()
    ->bind(PatientRepository::class, SqlPatientRepository::class)
    ->set(ApiClient::class, fn(ContainerInterface $c) => new ApiClient($c->get('apiUrl')))
    ->callback(ClassRouter::class, function (ClassRouter $router): void {
        $router->addAllowedNamespace('Api');
    })
    ->lock();
```

A module config should be **fast, declarative and side effect free**: no I/O, no
database connection, no request dependent work, no expensive instantiation. Prefer a
factory over an eager instance, so that the cost stays dormant:

```php
// avoid: built during boot, on every request of every route
->set(HugeClient::class, new HugeClient(...))

// prefer: built on first get()
->set(HugeClient::class, fn() => new HugeClient(...))
```

## Loading order

Modules are discovered by sorted folder name, so the order is deterministic across
filesystems. Each one gets a priority of 100, 200, 300... in that order unless it sets
its own with `$this->setPriority(50)`. Definitions are then merged from the lowest
priority to the highest, so a later module can override an earlier binding.

A second pass runs after every module has been merged, for features that depend on
what the other modules declared:

```php
$this->setDefinitionsCallback(function (Definitions $def): void {
    if ($def->has(SearchInterface::class)) {
        $def->bind(IndexerInterface::class, SearchIndexer::class);
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

A module does not own a url prefix by default: unmatched requests go to the router
default namespace (`App`). Opt in from the module config:

```php
$this->definitions()
    ->callback(ClassRouter::class, function (ClassRouter $router): void {
        $router->addAllowedNamespace('Admin'); // /admin/...
    })
    ->lock();
```

See [ClassRouter](class-router.md) for the full matching process.

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

Do **not** try to mutate the container per module (`$ctx->activateModule('Api')`). The
container is application scoped while the context is request scoped: in a worker,
requests hit `Api`, then `Admin`, then `Site` on the same container, and mutating the
composition per route would break singletons already resolved.

> **Modules are always registered, but their runtime dependencies are demand driven.
> Route specific behaviour belongs after routing, not in module registration.**
