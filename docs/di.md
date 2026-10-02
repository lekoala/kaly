---
layout: default
title: DI
nav_order: 1
---
# DI

> A strict PSR-11 container

The dependency injection is provided by the standalone
[`lekoala/kaly-di`](https://github.com/lekoala/kaly-di) package.

## Introduction

The component is split in three small pieces:

- `Definitions` — declares the object graph (bindings, factories, constructor parameters, callbacks);
- `Injector` — creates fresh objects and calls methods, resolving object dependencies through PSR-11;
- `Container` — a PSR-11 container that resolves and caches services.

In Kaly, these declarations belong in the owning module's
[`config.php`](modules.md#configphp). `Definitions` expresses the composition
directly; it does not load a separate application configuration tree. The container
then builds the declared objects on demand. Controllers and services receive their
dependencies through constructors; container lookups belong at the composition boundary.

## Usage

The container only exposes `has` and `get` (PSR-11). The basic usage is:

```php
use Kaly\Di\Container;
use Kaly\Di\Definitions;

$definitions = new Definitions();
$definitions->bind(UserRepositoryInterface::class, UserRepository::class);

$container = new Container($definitions);

$container->has(UserRepositoryInterface::class);
$container->get(UserRepositoryInterface::class);
```

Any dependency of a resolved class is injected automatically from the container.

## Definitions

`Definitions` is `final`. The main methods are:

- `bind(string $abstract, string $concrete)` — map an abstract id to a concrete class
  (note the **abstract → concrete** order);
- `set(string $id, string|object $value)` — register an object, a class name, or a
  factory closure;
- `rebind(string $id, string|object $value)` — intentionally replace an existing
  definition (`set()` on an already defined id throws a `DefinitionException`);
- `alias(string $alias, string $target)` — make an id resolve to another entry
  (same shared instance, no second callback run);
- `parameter(string $id, string $name, mixed $value)` — supply one constructor argument;
- `parameters(string $id, mixed ...$params)` — supply constructor arguments by name;
- `callback(string $id, callable $callback)` — run a callback once the service is
  instantiated;
- `merge(Definitions $other)`, `has(string $id)`, `lock()`.

Merging is additive: an id defined on both sides is a conflict, even with the
same value. A failed merge leaves the object unchanged and reports every
collision with its provenance.

```php
$definitions
    ->bind(MailerInterface::class, SmtpMailer::class)
    ->callback(Translator::class, static function (Translator $translator) use ($cacheDir): void {
        $translator->setCacheDir($cacheDir);
    });
```

Factories run when their service is first requested and receive the container.
Use it to resolve object dependencies when construction needs a factory:

```php
use Psr\Container\ContainerInterface;

$definitions->set(ReportExporter::class, static function (ContainerInterface $container): ReportExporter {
    return new ReportExporter($container->get(ReportRepository::class), format: 'csv');
});
```

Factories return an object or a class name. A closure that needs no dependencies
may omit the container argument. Services resolved through the same id are shared;
lazy construction does not mean a new instance on every request, nor does it delay
the construction of a dependency once its consumer needs it.

The definitions are locked once the application has loaded them: no service can be
registered afterwards. Constructing a standalone `Container` also locks the supplied
definitions.

## Constructor values

The container resolves objects, not scalar configuration entries. A string passed
to `set()` denotes a class name: `set('appName', 'my-app')` is invalid. Supply
scalars with `parameter()` / `parameters()`, or pass them directly in a factory.

For example, an application's `SearchClient` implements `SearchInterface` and takes
`string $url` and `string $apiKey` constructor arguments:

```php
use Kaly\Di\Definitions;
use Kaly\Util\Env;

$di
    ->bind(SearchInterface::class, SearchClient::class)
    ->parameters(
        SearchClient::class,
        url: Env::getString('SEARCH_URL'),
        apiKey: Env::getString('SEARCH_API_KEY'),
    );
```

Object dependencies are still autowired. Parameters configured for a concrete class
apply when it is resolved through a binding too; parameters for the requested
service id take precedence over the class parameters. Parameter names must match
the constructor. These parameters configure container construction;
`Injector::make()` does not read them.

Here `Env` is read during registration. To read a value only when the service is
built, supply a parameter closure, for example:

```php
$di->parameter(SearchClient::class, 'url', static fn() => Env::getString('SEARCH_URL'));
```

A parameter closure receives the container and returns the argument value. Choose
the timing deliberately: values captured during boot stay fixed, while a lazy read
uses the environment at first resolution. Neither approach provides per-request
configuration for a shared service. For `.env` loading and precedence, see
[App](app.md#env-variables).

Keep these reads in the composition root. Introduce a policy or options object only
when it is a meaningful dependency, as described in
[Modules](modules.md#values-and-configuration-objects).

## Injector

`Injector::make()` creates a class and `Injector::invoke()` calls a callable,
resolving the arguments from the container:

```php
$injector = new Injector($container);

$controller = $injector->make(MyController::class, request: $request);
$result = $injector->invoke([$controller, 'myAction'], ...$params);
```

`make()` creates a fresh instance of the concrete class, independently of its
container definition. It resolves object dependencies through the container, but
scalar arguments must be supplied explicitly, have a default, or be nullable.
Use `Container::get()` when you want the configured, shared service.

## Strict definitions

The container is permissive by default: it can instantiate any existing class. Bind an
interface to be able to resolve it — `has()` only returns `true` for an interface once
it has been defined.

Strictness applies to configuration mistakes, not to autowiring:

- defining the same id twice, merging two definitions that own the same id, or
  rebinding an unknown id throws a `DefinitionException` (unconditional, it also
  implements PSR-11 `ContainerExceptionInterface`);
- parameters configured for a constructor that does not declare them are rejected
  when the service is resolved — a typo'd parameter name fails fast instead of
  being ignored;
- `Injector::make()` / `Injector::invoke()` validate the argument list first:
  unknown named arguments, the same parameter given twice, surplus positionals
  and positional-after-named throw an `InvalidArgumentException`. Only pass what
  the constructor declares;
- a union parameter with several available candidates (`Foo|Bar`) is ambiguous
  and fails with an `UnresolvableParameterException`: pass the dependency
  explicitly.

## Exceptions

The component throws PSR-11 exceptions (`NotFoundExceptionInterface`,
`ContainerExceptionInterface`) as well as a `CircularReferenceException`.

