<?php

declare(strict_types=1);

namespace Kaly\Task;

use DateTimeImmutable;

/**
 * A dispatcher that can guarantee an absolute lower bound on execution.
 *
 * Depend on this capability when the application needs scheduling, and on
 * TaskDispatcher otherwise. Implementations must support future dates with
 * their configured engine; the direct dispatcher does not offer this capability.
 */
interface ScheduledTaskDispatcher extends TaskDispatcher
{
    /**
     * Without a date, or with a past date, the task is immediately eligible.
     * A future date must be honored: execution can be later, never earlier.
     * Limited storage precision must round up, and instants are independent
     * of their timezone. Durability and transaction guarantees belong to the engine.
     *
     * @throws \InvalidArgumentException If the task is not invocable.
     */
    public function dispatch(object $task, ?DateTimeImmutable $notBefore = null): void;
}
