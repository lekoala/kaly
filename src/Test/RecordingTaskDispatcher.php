<?php

declare(strict_types=1);

namespace Kaly\Test;

use InvalidArgumentException;
use Kaly\Di\Injector;
use Kaly\Task\TaskDispatcher;

/**
 * Records tasks without running them, for tests.
 *
 * When `dispatch()` returns, the task is queued in memory. Inspect the queue
 * with `tasks()`, or run it with `runAll()`.
 *
 * Kaly\Test is provided by Kaly but is not part of the production runtime.
 */
final class RecordingTaskDispatcher implements TaskDispatcher
{
    /**
     * @var list<callable&object>
     */
    private array $tasks = [];

    public function dispatch(object $task): void
    {
        if (!is_callable($task)) {
            throw new InvalidArgumentException('Task ' . $task::class . ' is not invocable');
        }

        $this->tasks[] = $task;
    }

    /**
     * The pending tasks, in dispatch order.
     *
     * @return list<callable&object>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * Runs pending tasks in dispatch order until the queue is empty.
     *
     * A task dispatched by a running task joins the end of the queue and runs
     * in the same call. Each task leaves the queue before it runs: on failure,
     * the exception propagates and the following tasks stay pending.
     */
    public function runAll(Injector $injector): void
    {
        while ($this->tasks !== []) {
            $task = array_shift($this->tasks);
            $injector->invoke($task);
        }
    }
}
