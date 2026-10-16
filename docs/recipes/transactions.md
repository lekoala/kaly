---
layout: default
title: Transactions recipe
nav_order: 10
---
# Transactions recipe

Kaly owns no transaction API. An atomic boundary is an
[application port](../database.md#typical-patterns) implemented by
infrastructure with the real tool. This recipe gives a minimal PDO
implementation, then describes the advanced capabilities that are deliberately
not standardized yet.

For Doctrine ORM and Cycle, the boundary also owns a mutable operation context:
see [Persistence](../database.md#transaction-scope-and-operation-context) and
the [Doctrine](doctrine.md) and [Cycle](cycle.md) recipes.

## The recommended pattern

The application declares what an operation requires:

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

Infrastructure implements it on one connection:

```php
use Closure;
use LogicException;
use PDO;
use Throwable;

final readonly class PdoUnitOfWork implements UnitOfWork
{
    /**
     * @param null|Closure(int $nanoseconds, bool $committed): void $observer
     */
    public function __construct(
        private PDO $pdo,
        private ?Closure $observer = null,
    ) {}

    public function transactionally(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('A transaction is already open on this connection');
        }
        $start = hrtime(true);
        $committed = false;
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            $committed = true;
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable) {
                    // The original failure matters more; the connection should be discarded
                }
            }
            throw $e;
        } finally {
            $this->observe(hrtime(true) - $start, $committed);
        }
    }

    private function observe(int $nanoseconds, bool $committed): void
    {
        if ($this->observer === null) {
            return;
        }
        try {
            ($this->observer)($nanoseconds, $committed);
        } catch (Throwable) {
            // An observer never changes the transactional outcome
        }
    }
}
```

The rules this adapter encodes:

- **Nesting fails fast.** A nested `transactionally()` throws instead of silently
  joining the outer transaction or simulating a savepoint. The caller that opens
  the transaction owns it.
- **The transaction state lives in the connection,** not in the service:
  `inTransaction()` is asked to PDO. The adapter is as shareable as its
  connection, which follows the [lifecycle rule](../database.md#lifecycle-rule):
  sequential runtimes may share one PDO handle, concurrent Fibers need one
  connection per operation.
- **A rollback failure never hides the original exception.** After it, treat the
  connection as broken; in a worker, let it [recycle](../tasks.md).
- **An observer cannot change the result.** It runs after commit or rollback,
  and its failures are discarded.
- PDO must use `PDO::ERRMODE_EXCEPTION`, the default since PHP 8.

Use cases depend on the port only, as in
[Persistence](../database.md#typical-patterns):

```php
$this->database->transactionally(function () use ($id, $name): void {
    $user = $this->users->get($id);
    $user->rename($name);
    $this->users->save($user);
});
```

Several databases are several ports (`MainDatabase`, `QueueDatabase`), each
bound to its own adapter: a name never decides that two resources share a
transaction.

### Keep the transaction short

Measure the transaction, not the use case. A transaction can last 12 ms inside a
use case that takes 3 seconds because of a network call. Never keep a
transaction open across an HTTP request, an LLM call or a mailer: prepare
outside, write inside, and hand side effects to a transactional
[task](../tasks.md) when they must follow the commit.

```php
$summary = $this->llm->summarize($thread);      // outside: slow, retryable

$this->database->transactionally(function () use ($thread, $summary): void {
    $this->threads->saveSummary($thread, $summary);
    $this->tasks->dispatch(new NotifyAgent($thread->id)); // same connection: outbox
});
```

### Observing transactions

The observer is a stateless closure, so it is safe on a shared adapter and works
the same in HTTP, CLI and workers. Wire it in the composition root:

```php
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

$definitions->set(UnitOfWork::class, static function (ContainerInterface $c): UnitOfWork {
    $logger = $c->get(LoggerInterface::class);

    return new PdoUnitOfWork(
        $c->get(PDO::class),
        static function (int $nanoseconds, bool $committed) use ($logger): void {
            $logger->debug('Transaction completed', [
                'duration_ms' => $nanoseconds / 1_000_000,
                'committed' => $committed,
            ]);
        },
    );
});
```

Log only static data: durations, outcomes, a constant operation name. Never
patient ids, emails or SQL parameters.

The request [`Profile`](server-timing.md) is a different matter. It belongs to
one `HttpContext`, while the adapter is shared by the whole application, and
`Application` code may not depend on `Kaly\Debug`. The shared adapter therefore
cannot record into the current profile, and must not try through a static or a
"current profile" holder: concurrent requests would mix their timings.

Record into a `Profile` only where both are explicit — an adapter built for one
operation with the profile it reports to:

```php
$database = new PdoUnitOfWork(
    $pdo,
    static fn(int $nanoseconds) => $profile->record('tx', $nanoseconds),
);
```

In a controller, `$ctx->profile()?->record()` around the use case call measures
the use case, not the transaction. Kaly provides no automatic transaction
metric: deciding how a profile travels to infrastructure without ambient state
is an open design question, not something to solve per application with a global.

## Advanced capabilities

The minimal port covers most operations. The following capabilities have real
uses — an outbox joining the caller's transaction, a savepoint to recover from a
booking conflict — but no single API fits PDO, DBAL and stateful ORMs yet. Kaly
does not standardize them. When an application needs one, make it an
**explicit, named method** of its own port, never a change in the meaning of
`transactionally()`.

| Capability | Meaning | What the implementation must guarantee |
| --- | --- | --- |
| Strict | Open a new transaction, fail if one is open | The default above |
| Join | Run inside the caller's transaction, or open one | Know whether a transaction is open: ask the connection, never a shared stack |
| Savepoint | Undo part of a transaction and continue | Unique savepoint names, release on success, rollback-to on failure; an ORM's memory state is **not** restored |
| Rollback-only | A joined part failed; the outer transaction may not commit | Mark it in the transaction, commit must then fail loudly instead of committing partial work |
| Named operations | Identify a boundary for observation | Static names only; a name carries no transactional semantics |

A savepoint, for example, stays a separate capability:

```php
interface ReservationDatabase extends UnitOfWork
{
    /**
     * Undo only $attempt when it fails, then rethrow; the outer transaction stays open.
     *
     * @template T
     *
     * @param callable(): T $attempt
     *
     * @return T
     */
    public function savepoint(callable $attempt): mixed;
}
```

```php
public function savepoint(callable $attempt): mixed
{
    if (!$this->pdo->inTransaction()) {
        throw new LogicException('A savepoint requires an open transaction');
    }
    $name = 'sp_' . bin2hex(random_bytes(8));
    $this->pdo->exec("SAVEPOINT {$name}");
    try {
        $result = $attempt();
        $this->pdo->exec("RELEASE SAVEPOINT {$name}");
        return $result;
    } catch (Throwable $e) {
        $this->pdo->exec("ROLLBACK TO SAVEPOINT {$name}");
        throw $e;
    }
}
```

With Doctrine ORM, a savepoint undoes rows, not the `EntityManager`: entities
changed during the attempt remain changed and managed. Recovering from a
conflict there usually means a fresh manager, not a savepoint.

### Why this is not in Kaly

A shared transaction engine would need to know, for every call, which
transaction and which profile belong to the current operation. In Kaly the
container is application-scoped and the request state lives in `HttpContext`;
answering that question requires either ambient state, which breaks under
Fibers, or an execution context passed explicitly through every layer, which no
use case has needed yet.

Until that question has a clean answer, the boundary stays an application port
of a few lines. A feature does not belong in the framework because the framework
could observe it: it must first be a responsibility Kaly legitimately owns.
