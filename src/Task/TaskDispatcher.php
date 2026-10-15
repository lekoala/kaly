<?php

declare(strict_types=1);

namespace Kaly\Task;

/**
 * Submits a task: an invocable object that owns its behavior.
 *
 * The constructor carries the task data, `__invoke()` receives its services:
 *
 * ```php
 * final readonly class GeneratePdf
 * {
 *     public function __construct(public string $documentId) {}
 *
 *     public function __invoke(PdfGenerator $generator): void
 *     {
 *         $generator->generate($this->documentId);
 *     }
 * }
 * ```
 *
 * `dispatch()` submits the task under the guarantees of the implementation:
 * it promises neither deferred execution, nor durability, nor atomicity with
 * the current transaction. Each implementation documents what is true when
 * `dispatch()` returns.
 */
interface TaskDispatcher
{
    /**
     * @throws \InvalidArgumentException If the task is not invocable.
     */
    public function dispatch(object $task): void;
}
