---
layout: default
title: Tasks
nav_order: 23
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
(PostgreSQL) and [Symfony Messenger](#symfony-messenger).

Both Kaly dispatchers reject a non-invocable object and accept closures. A
durable engine may restrict tasks further, eg to persistable data.

The direct dispatcher is a production engine for short work that needs no
deferred durability. Inside a transaction, it runs the task before the commit.

## Testing

`RecordingTaskDispatcher` records tasks without running them:

```php
$tasks = new RecordingTaskDispatcher();
$useCase = new PublishDocument($tasks);

$useCase->publish('doc-123');

$this->assertEquals([new GeneratePdf('doc-123')], $tasks->tasks());
```

`runAll()` runs the queue in dispatch order until it is empty, including tasks
dispatched by running tasks. Each task leaves the queue before it runs: on
failure, the exception propagates and the following tasks stay pending.

```php
$tasks->runAll($container->get(Injector::class));
```

Recording validates neither transactions nor recovery: test those with the real
engine.

## Symfony Messenger

Messenger can run the same tasks unchanged. One generic handler delegates
every message of a dedicated bus to the Injector:

```php
use Kaly\Di\Injector;
use Kaly\Task\TaskDispatcher;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerTaskDispatcher implements TaskDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {}

    public function dispatch(object $task): void
    {
        $this->bus->dispatch($task);
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
