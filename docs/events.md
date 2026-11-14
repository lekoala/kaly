---
layout: default
title: Events
nav_order: 25
---
# Events

Kaly has no event system and will not grow one: there is no `Kaly\Event`. An
event is a reaction to something that happened — a user registered, an order
was paid — and the right tool depends on what the reaction must guarantee, not
on a framework feature.

## Three questions

Ask them in order, for each reaction:

1. **Must the reaction succeed for the operation to count?** Then it is not an
   event: call it explicitly, inside the operation (and its transaction). An
   invoice line written with the payment belongs to the payment.
2. **Can it be deferred?** If not, react synchronously, in the same process.
3. **Must it survive a crash?** If so, write it with the business change, in the
   same transaction, and let a worker publish it.

| Answer | Tier | Tool |
|---|---|---|
| Must succeed with the operation | Explicit call | A service, called by the operation |
| Not deferrable, failure acceptable to report | Synchronous | PSR-14 dispatcher |
| Deferrable, a lost reaction is tolerable | Deferred | A task through `TaskDispatcher` |
| Deferrable, must survive a crash | Durable | Transactional outbox |

## Synchronous: PSR-14

A PSR-14 dispatcher (`psr/event-dispatcher`) is an ordinary service: choose an
implementation, declare it in the composition, register listeners explicitly.
Listeners are not discovered, for the same reason routes and services are not
(see [Architecture](architecture.md#php-attributes)).

```php
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

$di->set(EventDispatcherInterface::class, static function (ContainerInterface $c): EventDispatcherInterface {
    $dispatcher = new EventDispatcher();
    // The listener service is resolved when the event fires, not at boot
    $dispatcher->addListener(UserRegistered::class, static fn(UserRegistered $event) => $c->get(AuditLog::class)->record($event));
    return $dispatcher;
});
```

A synchronous listener runs inside the request, and inside the current
transaction if there is one: it runs before the commit, and its side effects
are not rolled back with it. A mail sent by a listener leaves even when the
transaction fails afterwards. With a single listener, a direct call is often
clearer than an event.

## Deferred: tasks

A reaction that can wait is a [task](tasks.md): an invocable object submitted
through `TaskDispatcher`.

```php
$this->tasks->dispatch(new SendWelcomeMail($user->id));
```

Keep the contract and the engine apart. `TaskDispatcher` promises neither
deferred execution, nor durability, nor atomicity with the current transaction:

| | Promised by |
|---|---|
| `dispatch()` submits the task | The `TaskDispatcher` contract |
| The task runs later, in a worker | The engine |
| The task survives a crash | The engine |
| The task is written in the business transaction | The engine and the connection it uses |

Code that depends on one of the last three guarantees depends on the engine,
not on the contract: say so in the composition, and test it with the real
engine. `DirectTaskDispatcher` runs the task immediately, before the commit;
`RecordingTaskDispatcher` validates neither transactions nor recovery.

## Durable: transactional outbox

A reaction that must survive a crash is written with the business change, in
the same transaction: durable with the commit, gone with a rollback. A worker
then publishes it, and the reaction is idempotent, since a crash after
publication but before acknowledgement publishes it again.

With an SQL task engine on the application connection, the dispatch itself is
the outbox: the task row is written in the business transaction.

```php
$this->database->transactionally(function () use ($message): void {
    $this->messages->add($message);
    $this->tasks->dispatch(new SendContactAcknowledgement($message->id));
});
```

That guarantee belongs to the engine, which documents it:
[kaly-tasks](https://github.com/lekoala/kaly-tasks#where-the-queue-lives) gives
it on the application connection, not with a dedicated queue database. When
the queue lives elsewhere, or the reaction goes to a broker, write an
application outbox table in the business transaction and publish it from a
worker.
