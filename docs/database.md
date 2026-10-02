---
layout: default
title: Persistence
nav_order: 15
---
# Persistence

Kaly does not provide a database abstraction or ORM.

Application code should depend on application-defined persistence ports.
Database and ORM implementations belong to infrastructure. This is deliberate:
Kaly never calls the database itself (unlike `RendererInterface`, which the
dispatcher needs to render a `View`), so there is nothing for the framework
to own — see [Architecture](architecture.md) and [Runtime](runtime.md).

## Lifecycle rule

The application container is application-scoped: what it shares must be safe
to share. A stateful persistence context (Doctrine `EntityManager` with its
identity map and unit of work, a Cycle ORM heap, a connection inside a
transaction) must therefore have a lifecycle narrower than the application.

> Do not assume that wrapping a stateful EntityManager in a shared service
> makes it worker- or concurrency-safe.

```text
sequential (PHP-FPM, sequential worker)   shared handle may be acceptable
concurrent (Fibers, event loop)           fresh context per operation required
```

Kaly provides no request scope for this: the adapter decides, or documents a
narrower compatibility — exactly like `NativePhpSession` (sequential ✓,
concurrent ✗).

## Transaction scope and operation context

| Backend | mutable context | transaction | after error | recommended pattern |
|---|---|---|---|---|
| PDO | connection/transaction | native | connection reusable depending on driver error | shared handle |
| Doctrine DBAL | connection/transaction | `Connection::transactional()` | connection normally reusable | shared handle |
| Doctrine ORM | EntityManager + UnitOfWork | `wrapInTransaction()` (flushes before commit) | **EM closed / must be discarded** | **per-operation context** |
| Cycle | ORM heap | transaction + heap | **heap must be explicitly cleaned/recreated** | **per-operation context** |

A `transactionally(callable(): T)` port fits a PDO/DBAL-style transactional
connection, but it is not a general persistence contract once a backend owns a
mutable operation context. Keep two levels distinct:

```text
Atomic boundary:      UnitOfWork::transactionally(...) → all or nothing
Operation context:    PersistenceContext given to the callback →
                      the repositories used here belong to the same context
```

When several backends are possible, design for the most lifecycle-demanding
one, not for the richest API.

## Typical patterns

```text
simple SQL:          Repository + application UnitOfWork sharing one handle
stateful ORM:        operation-scoped persistence context, passed explicitly
read-only dataset:   application Catalog/Provider port (DB, JSON, API behind it)
remote system:       application Gateway port
```

Repositories are the business ports:

```php
interface UserRepository
{
    public function get(UserId $id): User;

    public function save(User $user): void;
}
```

For plain SQL, a small application port is enough:

```php
interface UnitOfWork
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactionally(callable $operation): mixed;
}
```

```php
final class RenameUser
{
    public function __construct(
        private UserRepository $users,
        private UnitOfWork $database,
    ) {}

    public function __invoke(UserId $id, string $name): void
    {
        $this->database->transactionally(function () use ($id, $name): void {
            $user = $this->users->get($id);
            $user->rename($name);
            $this->users->save($user);
        });
    }
}
```

Infrastructure uses its real tool directly — no universal query API in between:

```php
final class PdoUserRepository implements UserRepository
{
    public function __construct(private PDO $pdo) {}
}
```

For a stateful ORM under concurrency, the simple port above is insufficient:
a shared repository cannot guess which fresh `EntityManager` carries the
transaction. Pass the operation context explicitly instead:

```php
interface UserPersistenceContext
{
    public function users(): UserRepository;
}

interface UserUnitOfWork
{
    /**
     * @template T
     *
     * @param callable(UserPersistenceContext): T $operation
     *
     * @return T
     */
    public function transactionally(callable $operation): mixed;
}
```

```php
$this->unitOfWork->transactionally(
    function (UserPersistenceContext $ctx) use ($id, $name): void {
        $user = $ctx->users()->get($id);
        $user->rename($name);
        $ctx->users()->save($user);
    },
);
```

The adapter creates a fresh EntityManager, binds repositories to it, runs the
callback, then clears and closes — no global state, scope, or Fiber-local.
Same shape works for HTTP, CLI, workers, queue, and tests.

Multiple databases need no connection names: typed DI is enough
(`MainDatabase`, `AnalyticsDatabase` as separate application ports).

## Dependency rules

```text
Domain:         no Doctrine / Cycle / PDO / infrastructure
Application:    application ports only, no Doctrine / Cycle / PDO
Infrastructure: implements ports with any backend (PDO, DBAL, ORM, Cycle)
Controller:     no EntityManager / Connection / PDO
```

## Non-goals

No portable queries, repositories, mapping, migrations, or identical ORM
semantics. Backends differ on nested transactions and savepoints; application
ports are best kept silent on that difference — nested `transactional()`
should fail fast rather than simulate savepoints. A future savepoint need is
an explicit capability, not a silent change of meaning.

