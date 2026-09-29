# DI

> A strict PSR-11 container

The dependency injection is provided by the standalone
[`lekoala/kaly-di`](https://github.com/lekoala/kaly-di) package.

## Introduction

The component is split in three small pieces:

- `Definitions` — a declarative configuration object (bindings, values, callbacks);
- `Injector` — creates classes and calls methods based on the definitions;
- `Container` — a PSR-11 container that resolves and caches services.

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
- `set(string $id, string|object $value)` — register a value, a class name, or a
  factory callable;
- `rebind(string $id, string|object $value)` — intentionally replace an existing
  definition (`set()` on an already defined id throws a `DefinitionException`);
- `alias(string $alias, string $target)` — make an id resolve to another entry
  (same shared instance, no second callback run);
- `callback(string $id, callable $callback)` — run a callback once the service is
  instantiated;
- `merge(Definitions $other)`, `has(string $id)`, `lock()`.

Merging is additive: an id defined on both sides is a conflict, even with the
same value. A failed merge leaves the object unchanged and reports every
collision with its provenance.

```php
$definitions
    ->bind(MailerInterface::class, SmtpMailer::class)
    ->set('appName', 'my-app')
    ->callback(Translator::class, function (Translator $translator): void {
        $translator->setCacheDir($cacheDir);
    });
```

Factories receive the container:

```php
$definitions->set('logger', function (ContainerInterface $container): LoggerInterface {
    return new FileLogger($container->get('logFile'));
});
```

The definitions are locked once the application has loaded them: no service can be
registered afterwards.

## Injector

`Injector::make()` creates a class and `Injector::invoke()` calls a callable,
resolving the arguments from the container:

```php
$injector = new Injector($container);

$controller = $injector->make(MyController::class, request: $request);
$result = $injector->invoke([$controller, 'myAction'], ...$params);
```

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
