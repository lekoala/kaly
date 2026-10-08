---
layout: default
title: Application composition
nav_order: 5
---
# Application composition

Kaly provides the application runtime and a small set of HTTP primitives. It
does not try to own every technical concern of an application, so a Kaly
application is expected to compose PHP libraries explicitly.

```text
Kaly
  HTTP lifecycle
  routing
  middleware
  request context
  DI composition
  views
  sessions and auth primitives
  basic translation
  testing primitives

Application
  use cases
  authorization policies
  integration ports
  registries
  persistence choices
  process topology

Libraries
  database and ORM
  mail
  queues
  console
  cache
  rate limiting
  HTTP clients
  encryption
  observability
```

This is deliberate. The goal is not to avoid dependencies, but to avoid making
a library's framework integration the architecture of the application. Composer
makes library code available; the application chooses how to compose it, as
described in [Building a Kaly application](application-structure.md) and
[Modules](modules.md).

## Three ways to use a library

A third-party component normally falls into one of three categories.

### 1. Use it directly

Use the library directly when it is an implementation detail of the layer that
consumes it.

```text
HTTP middleware
    ↓
rate limiter library
```

There is no benefit in creating an application-level abstraction for a concept
the application does not own. Login throttling, for example, is an HTTP
boundary concern:

```php
final class LoginThrottle implements MiddlewareInterface
{
    public function __construct(
        private LimiterFactory $limiter,
    ) {}
}
```

No application-owned `RateLimiterInterface` is required.

### 2. Put it behind an application capability

Introduce a port when the application itself owns the concept and its use cases
depend on it.

```text
Application
    ↓
DeliveryChannel
    ↓
Infrastructure
    ↓
mail library
```

Prefer:

```php
interface DeliveryChannel
{
    public function deliver(Delivery $delivery): DeliveryResult;
}
```

over:

```php
interface MailLibraryAdapterInterface
{
    public function send(Email $email): void;
}
```

The first is an application capability. The second merely renames a vendor API.
The same `DeliveryChannel` contract can be backed by a mail library or by an
implementation with no mail library at all, while vendor errors are translated
at the infrastructure boundary.

### 3. Keep a compatibility bridge temporarily

During a migration it can be cheaper to preserve an existing abstraction for a
while. An existing authorization voter, for instance, can remain usable while
the application moves toward explicit policies. A bridge should be identified
as such: it is not automatically the architecture to promote for new code.

## Wrap capabilities, not libraries

A dependency being external does not imply that it needs an application
interface. Ask:

> Does the application own this capability, or is this library merely an
> implementation detail of the current layer?

Typical application capabilities include:

```text
PasswordHasher
DeliveryChannel
CommandBus
PaymentGateway
AccountingGateway
WebhookSender
```

Typical direct dependencies include:

```text
HTTP rate limiter
PSR logger
cache implementation in Infrastructure
HTTP client in Infrastructure
console shell
database driver
```

## Composition roots

Each module should compose the dependencies it owns in its `config.php`. Prefer
explicit composition:

```php
$di->bind(
    PasswordHasher::class,
    ArgonPasswordHasher::class,
);
```

over discovery. For application-wide overrides or final composition, use the
application's final configuration pass. A useful rule is:

> The code that decides which implementation is used should be easy to find
> with a text search.

This is one reason Kaly does not recommend service tags, attribute discovery or
compiler passes as its primary composition mechanism. See
[Modules](modules.md#configphp) and [DI](di.md).

## Collections and registries

Applications often need collections of contributions:

```text
message handlers
delivery channels
operations
search providers
schema contributors
operational signals
```

Do not immediately turn this into framework-level service tags. Start with an
application-owned registry:

```php
$di->callback(
    HandlerRegistry::class,
    static function (HandlerRegistry $handlers): void {
        $handlers->add(
            PlaceOrder::class,
            PlaceOrderHandler::class,
        );
    },
);
```

The registry stays local to the owning module and does not require scanning or
metadata compilation. Only consider a generic DI primitive when the same
composition pattern is repeated across several unrelated kinds of collection.

A recommended progression:

```text
explicit composition
        ↓
small application helper
        ↓
framework primitive only after repeated evidence
```

## Service lifetime

Kaly's container is application-scoped, so a service registered in the
container must be safe to reuse across requests and, potentially, across a
long-running worker. Shared services should normally be immutable, stateless,
re-entrant, or explicit managers and factories for shorter-lived state. Do not
put mutable request state in an application-scoped service.

A stateful dependency does not automatically imply that Kaly needs a
request-scoped container: often the correct lifecycle is shorter than a
request. The owner of a resource should open and close it explicitly.

```php
$entityManagers->with(
    function (EntityManagerInterface $em): void {
        // one operation
    },
);
```

See [DI](di.md#stateful-services-and-request-lifetime) for the rule and
[Runtime](runtime.md) for its consequences in worker and concurrent runtimes.

## Choosing persistence

Kaly does not prescribe an ORM, and it does not require one. Choose persistence
tools according to the model and lifecycle of the application rather than
according to framework integration. A useful application may use several
persistence styles at the same time — for instance an ORM for the write model
and SQL for read models or reporting.

The important decision is the lifetime of the persistence state, not the brand
of the tool. See [Persistence](database.md) for the lifecycle rule, the
decision checklist and the comparison between common backends.

## Authorization

Kaly recommends separating coarse HTTP access from object-level authorization.

```text
HTTP
    ↓
middleware
    coarse permission
    ↓
use case
    ↓
explicit policy
```

For example:

```php
final class AccountPolicy
{
    public function canEdit(
        Actor $actor,
        Account $account,
    ): bool {
        // ...
    }
}
```

The use case calls this policy directly. This avoids introducing a policy
dispatcher, subject matching, voter priorities or a global authorization
registry before such complexity is actually needed. A voter-style system can
remain useful as a migration bridge, but should not be assumed necessary for
new applications. See [Auth](auth.md) and [Security](security.md).

## CLI and non-HTTP processes

A Kaly application can be booted without creating an HTTP request:

```php
$app = App::create($root);
$app->boot();

$container = $app->container();
```

The same composition root can therefore serve `public/index.php`, `bin/console`,
workers, cron scripts and maintenance tools. A console shell can simply consume
the booted application's services. See the [console](recipes/console.md) and
[cron](recipes/cron.md) recipes.

## Workers and queues

Kaly does not need to own a message bus. A worker library can be composed
directly, and the application may expose a narrower port:

```php
interface CommandBus
{
    public function dispatch(object $message): void;
}
```

while Infrastructure builds the actual bus and handlers. A production worker
still needs application-specific decisions around retry policy, failed
messages, graceful shutdown, connection recovery, stable serialization, logging
and observability. Those are not automatically framework responsibilities. See
[Runtime](runtime.md#worker-mode).

## Prefer standards when the standard expresses the capability

Do not invent Kaly interfaces when an existing neutral standard already models
the dependency. Prefer `Psr\Clock\ClockInterface`, with `Kaly\Clock\SystemClock`
and `Kaly\Clock\FrozenClock`, rather than introducing a Kaly-specific clock
interface. The same principle applies to PSR logging and other sufficiently
narrow standards. See [Utilities](utils.md).

A standard interface is not automatically suitable everywhere, however.
`Psr\Container\ContainerInterface` is acceptable in composition and
infrastructure code, but should not become a service locator inside Domain or
Application code.

## Vendor types in the domain

Not every vendor value object requires an application wrapper, but make this an
explicit decision. Using a vendor UUID type throughout a domain is a direct
dependency on that package. That can be acceptable when the type has the
semantics the domain needs, replacing it is not a realistic requirement, and
the convenience outweighs the coupling. Do not call it a framework-neutral
standard, however. Alternatives include a domain-specific ID value object, a
string at the boundaries, or a vendor value object accepted deliberately.

## Translation

Avoid making Domain or Application depend on the mechanics of a concrete
translation engine unless translation is genuinely part of that layer's
responsibility. Prefer expressing translatable intent — a translation key, a
`Translatable` value, a domain error carrying semantic information — and resolve
it near the presentation boundary. Using an external translator directly can
remain a compatibility bridge when migrating an existing application. See
[i18n](i18n.md).

## How to evaluate a third-party component

Before adding a library, ask:

### Can it run standalone?

Prefer libraries whose useful core does not require their original framework.

### Is its configuration explicit?

Can the application construct it directly in PHP?

### What is its lifetime?

Is the component immutable, application-scoped, request-scoped,
operation-scoped or worker-scoped? Does it retain mutable state?

### Does it work in long-running processes?

Look for reset requirements, persistent connections, global state, static
caches and request assumptions.

### Are errors vendor-specific?

Decide whether errors should remain infrastructure exceptions or be translated
into application outcomes.

### Is it testable without infrastructure?

Null transports, in-memory adapters and deterministic clocks are valuable.

### Does it force discovery?

A library that can only work through framework scanning, tags or compiler
passes will generally require more integration work than one exposing ordinary
constructors and registries.

## Kaly should stay small

When integration feels repetitive, do not immediately add a framework feature.
Use this order:

```text
Can ordinary PHP solve it clearly?
        ↓ yes
keep it application-owned

Is the same helper repeated inside the application?
        ↓ yes
extract an application helper

Does the same pattern appear across unrelated Kaly applications?
        ↓ yes
consider a Kaly primitive
```

This rule applies particularly to service collections, request-scoped services,
console abstractions, message buses, scheduler abstractions and semantic
configuration systems. Explicit composition stays small enough and keeps the
dependency graph visible.
