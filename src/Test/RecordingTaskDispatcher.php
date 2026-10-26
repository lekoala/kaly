<?php

declare(strict_types=1);

namespace Kaly\Test;

use DateTimeImmutable;
use InvalidArgumentException;
use Kaly\Clock\SystemClock;
use Kaly\Di\Injector;
use Kaly\Task\ScheduledTaskDispatcher;
use Psr\Clock\ClockInterface;

/**
 * Records tasks without running them, for tests.
 *
 * When `dispatch()` returns, the task is queued in memory. Inspect the queue
 * with `tasks()`, or run eligible tasks with `runAll()`. Implements
 * ScheduledTaskDispatcher; its injected clock controls eligibility in tests.
 *
 * Kaly\Test is provided by Kaly but is not part of the production runtime.
 */
final class RecordingTaskDispatcher implements ScheduledTaskDispatcher
{
    /**
     * @var list<array{task:callable&object,notBefore:DateTimeImmutable|null}>
     */
    private array $tasks = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function dispatch(object $task, ?DateTimeImmutable $notBefore = null): void
    {
        if (!is_callable($task)) {
            throw new InvalidArgumentException('Task ' . $task::class . ' is not invocable');
        }

        $this->tasks[] = ['task' => $task, 'notBefore' => $notBefore];
    }

    /**
     * The pending tasks, in dispatch order.
     *
     * @return list<callable&object>
     */
    public function tasks(): array
    {
        return array_column($this->tasks, 'task');
    }

    /**
     * Runs eligible tasks in dispatch order until no task is due.
     *
     * A task dispatched by a running task joins the end of the queue and runs
     * in the same call if due. Future tasks stay queued. Each task leaves the queue before it runs: on failure,
     * the exception propagates and the following tasks stay pending.
     */
    public function runAll(Injector $injector): void
    {
        while ($this->tasks !== []) {
            $now = $this->clock->now();
            foreach ($this->tasks as $index => $entry) {
                if ($entry['notBefore'] === null || $entry['notBefore'] <= $now) {
                    array_splice($this->tasks, $index, 1);
                    $injector->invoke($entry['task']);
                    continue 2;
                }
            }
            return;
        }
    }
}
