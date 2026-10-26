---
layout: default
title: Tasks
nav_order: 24
---
# Tasks

A task is work to do whose submission may be decoupled from its execution:
generate a PDF, call an API, process a webhook.

Task dispatch is an optional application execution primitive. Kaly provides a
minimal dispatch contract, `Kaly\Task\TaskDispatcher`, and direct execution
through its Injector. Persistent queues, workers and transport-specific
guarantees belong to optional implementations.

```php
$this->tasks->dispatch(new GeneratePdf($documentId));
```

## A task owns its behavior

A task is a plain invocable object. Its constructor carries the data, its
`__invoke()` receives the services:

```php
namespace App\Documents\Application\Task;

final readonly class GeneratePdf
{
    public function __construct(
        public string $documentId,
    ) {}

    public function __invoke(PdfGenerator $generator): void
    {
        $generator->generate($this->documentId);
    }
}
```

No interface, attribute or handler registry: `__invoke()` replaces them, and
the task has no dependency on Kaly. Services are resolved by the
[Injector](di.md) when the task runs, never stored in the task, so a durable
engine can persist the data alone. A task tests without Kaly:

```php
(new GeneratePdf('doc-123'))(new FakePdfGenerator());
```

When the work grows, keep the task as the entry point and delegate to an
application service (`__invoke(SupportConversationProcessor $processor)`).

A task never receives the current HTTP context implicitly. Identity or
authorization travels in the task data and is checked again when it runs.

## Choosing an engine

Kaly registers no `TaskDispatcher`: the application chooses its engine in its
composition, so a task meant for a durable queue never runs by accident inside
an HTTP transaction.

```php
$app->configure(fn(Definitions $di) => $di->bind(TaskDispatcher::class, DirectTaskDispatcher::class));
```

`dispatch()` submits the task under the guarantees of the engine. It promises
neither deferred execution, nor durability, nor atomicity with the current
transaction:

| Dispatcher                          | When `dispatch()` returns                                     |
|-------------------------------------|---------------------------------------------------------------|
| `Kaly\Task\DirectTaskDispatcher`    | The task has completed, or its exception propagates unchanged |
| `Kaly\Test\RecordingTaskDispatcher` | The task is queued in memory                                  |
| Durable engine                      | The engine documents its own guarantees                       |

Durable engines include [kaly-tasks](https://github.com/lekoala/kaly-tasks)
(PostgreSQL, MySQL and SQLite) and [Symfony Messenger](#symfony-messenger).

Both Kaly dispatchers reject a non-invocable object and accept closures. A
durable engine may restrict tasks further, eg to persistable data.

For persistent queues, validating a task's name and arguments does not establish
its origin. Signatures can detect changes by a storage writer who lacks the
signing key, but authorization and replay protection remain application rules.
See the [trust boundary](security.md#trust-boundary).

The direct dispatcher is a production engine for short work that needs no
deferred durability. Inside a transaction, it runs the task before the commit.

## Scheduling

`TaskDispatcher` exposes only `dispatch(object $task)`. Applications that need a
deadline depend on `Kaly\Task\ScheduledTaskDispatcher`, which extends that contract
with an optional absolute lower bound on the same method:

```php
use Kaly\Task\ScheduledTaskDispatcher;

final readonly class SmsService
{
    public function __construct(private ScheduledTaskDispatcher $tasks) {}

    public function send(string $deliveryId, DateTimeImmutable $scheduledAt): void
    {
        $this->tasks->dispatch(new DeliverSms($deliveryId), notBefore: $scheduledAt);
    }
}
```

Use an injected PSR-20 clock to express business dates, for example
`$clock->now()->modify('+10 seconds')`. A past date is immediately eligible; a
future date must be honored. Execution may be later, depending on polling and
precision. Durable SQL engines keep database time for availability and leases.

`DirectTaskDispatcher` implements only `TaskDispatcher`. Bind
`ScheduledTaskDispatcher` explicitly to a capable engine: a direct binding does
not satisfy a service's scheduling dependency. `RecordingTaskDispatcher` offers
both capabilities for tests. When one engine serves both contracts, bind the
scheduling interface and alias `TaskDispatcher` to that same instance.

## Testing

`RecordingTaskDispatcher` records tasks without running them:

```php
$tasks = new RecordingTaskDispatcher();
$useCase = new PublishDocument($tasks);

$useCase->publish('doc-123');

$this->assertEquals([new GeneratePdf('doc-123')], $tasks->tasks());
```

`runAll()` runs eligible tasks in dispatch order until none is due, including
eligible tasks dispatched by running tasks. Future tasks remain recorded and do
not block immediate tasks. Inject a `FrozenClock` into `RecordingTaskDispatcher`
to advance time in tests without sleeping. Each task leaves the queue before it runs: on
failure, the exception propagates and the following tasks stay pending.

```php
$tasks->runAll($container->get(Injector::class));
```

Recording validates neither transactions nor recovery: test those with the real
engine.

## Symfony Messenger

Messenger can run the same tasks unchanged. This scheduling adapter requires
a dedicated asynchronous bus whose transport honors delayed delivery. One
generic handler delegates its messages to the Injector:

```php
use Kaly\Clock\SystemClock;
use Kaly\Di\Injector;
use Kaly\Task\ScheduledTaskDispatcher;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final readonly class ScheduledMessengerTaskDispatcher implements ScheduledTaskDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    public function dispatch(object $task, ?DateTimeImmutable $notBefore = null): void
    {
        $stamps = [];
        if ($notBefore !== null) {
            $now = $this->clock->now();
            $milliseconds = ($notBefore->getTimestamp() - $now->getTimestamp()) * 1000
                + (int) ceil(((int) $notBefore->format('u') - (int) $now->format('u')) / 1000);
            if ($milliseconds > 0) {
                $stamps[] = new DelayStamp($milliseconds);
            }
        }
        $this->bus->dispatch($task, $stamps);
    }
}

$handlers = new HandlersLocator([
    '*' => [static fn(object $task) => $injector->invoke($task)],
]);
```

- The `'*'` handler takes every message: keep a bus for tasks only.
- Messenger wraps a failure in `HandlerFailedException`, with the task
  exception as previous.
- Messenger serializes with PHP `serialize()` by default: configure its
  serializer if stored tasks must be a controlled format.
- Switching engines does not migrate tasks already waiting in the old one.

Register this adapter as `ScheduledTaskDispatcher` only with a transport that
honors `DelayStamp`. The adapter rounds up to milliseconds; transport precision
and latency can delay execution further. Test the guarantee with that transport.
An adapter for a synchronous or unsupported transport implements only
`TaskDispatcher`, with `dispatch(object $task)` forwarding directly to the bus.
Do not expose the scheduling interface behind a runtime capability flag.
