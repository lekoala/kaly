<?php

declare(strict_types=1);

namespace Kaly\Task;

use InvalidArgumentException;
use Kaly\Di\Injector;

/**
 * Runs the task immediately, resolving its `__invoke()` services through the
 * container.
 *
 * When `dispatch()` returns, the task has completed. Its exceptions propagate
 * to the caller unchanged. Inside a transaction, the task runs before the
 * commit.
 */
final readonly class DirectTaskDispatcher implements TaskDispatcher
{
    public function __construct(
        private Injector $injector,
    ) {}

    public function dispatch(object $task): void
    {
        if (!is_callable($task)) {
            throw new InvalidArgumentException('Task ' . $task::class . ' is not invocable');
        }

        $this->injector->invoke($task);
    }
}
