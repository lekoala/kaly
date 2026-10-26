<?php

declare(strict_types=1);

namespace Kaly\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Kaly\Clock\FrozenClock;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Task\DirectTaskDispatcher;
use Kaly\Task\ScheduledTaskDispatcher;
use Kaly\Task\TaskDispatcher;
use Kaly\Test\RecordingTaskDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use stdClass;

final class TaskTestPdfGenerator
{
    /**
     * @var list<string>
     */
    public array $generated = [];

    public function generate(string $documentId): void
    {
        if ($documentId === 'broken') {
            throw new RuntimeException('Generation failed');
        }
        $this->generated[] = $documentId;
    }
}

final readonly class TaskTestGeneratePdf
{
    public function __construct(
        public string $documentId,
    ) {}

    public function __invoke(TaskTestPdfGenerator $generator): void
    {
        $generator->generate($this->documentId);
    }
}

final readonly class TaskTestGenerateThenArchive
{
    public function __construct(
        public string $documentId,
    ) {}

    public function __invoke(TaskTestPdfGenerator $generator, TaskDispatcher $tasks): void
    {
        $generator->generate($this->documentId);
        $tasks->dispatch(new TaskTestGeneratePdf($this->documentId . '-archive'));
    }
}

class TaskTest extends TestCase
{
    public function testTaskRunsWithoutKaly(): void
    {
        $generator = new TaskTestPdfGenerator();

        (new TaskTestGeneratePdf('doc-1'))($generator);

        $this->assertSame(['doc-1'], $generator->generated);
    }

    public function testDirectDispatcherIsAutowired(): void
    {
        $container = new Container(Definitions::create()->bind(TaskDispatcher::class, DirectTaskDispatcher::class));

        $tasks = $container->get(TaskDispatcher::class);
        $tasks->dispatch(new TaskTestGeneratePdf('doc-1'));

        $this->assertInstanceOf(DirectTaskDispatcher::class, $tasks);
        $this->assertSame(['doc-1'], $container->get(TaskTestPdfGenerator::class)->generated);
    }

    public function testDirectDispatcherPropagatesFailures(): void
    {
        $tasks = new DirectTaskDispatcher(new Injector(new Container()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Generation failed');
        $tasks->dispatch(new TaskTestGeneratePdf('broken'));
    }

    public function testDirectDispatcherAcceptsClosures(): void
    {
        $generator = new TaskTestPdfGenerator();
        $tasks = new DirectTaskDispatcher(new Injector(new Container([TaskTestPdfGenerator::class => $generator])));

        $tasks->dispatch(static fn(TaskTestPdfGenerator $generator) => $generator->generate('doc-1'));

        $this->assertSame(['doc-1'], $generator->generated);
    }

    public function testDirectDispatcherRejectsNonInvocableTasks(): void
    {
        $tasks = new DirectTaskDispatcher(new Injector(new Container()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Task stdClass is not invocable');
        $tasks->dispatch(new stdClass());
    }

    public function testDirectBindingDoesNotProvideTheSchedulingCapability(): void
    {
        $container = new Container(Definitions::create()->bind(TaskDispatcher::class, DirectTaskDispatcher::class));
        $container->get(TaskDispatcher::class)->dispatch(new TaskTestGeneratePdf('immediate'));
        $this->assertSame(['immediate'], $container->get(TaskTestPdfGenerator::class)->generated);

        $this->expectException(NotFoundExceptionInterface::class);
        $container->get(ScheduledTaskDispatcher::class);
    }

    public function testRecordingDispatcherKeepsFutureTasksUntilTheirDeadline(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-26T10:00:00.123456Z'));
        $generator = new TaskTestPdfGenerator();
        $tasks = new RecordingTaskDispatcher($clock);
        $dispatcher = (new Container([ScheduledTaskDispatcher::class => $tasks]))->get(ScheduledTaskDispatcher::class);
        $future = new TaskTestGeneratePdf('future');
        $dispatcher->dispatch($future, notBefore: $clock->now()->modify('+1 microsecond'));
        $tasks->dispatch(new TaskTestGeneratePdf('past'), notBefore: $clock->now()->modify('-1 second'));
        $tasks->dispatch(new TaskTestGeneratePdf('now'), notBefore: $clock->now());
        $tasks->dispatch(new TaskTestGeneratePdf('immediate'));
        $injector = new Injector(new Container([TaskTestPdfGenerator::class => $generator]));

        $tasks->runAll($injector);
        $this->assertSame(['past', 'now', 'immediate'], $generator->generated);
        $this->assertSame([$future], $tasks->tasks());

        $clock->modify('+1 microsecond');
        $tasks->runAll($injector);
        $this->assertSame(['past', 'now', 'immediate', 'future'], $generator->generated);
        $this->assertSame([], $tasks->tasks());
    }

    public function testRecordingDispatcherRecordsWithoutRunning(): void
    {
        $tasks = new RecordingTaskDispatcher();
        $first = new TaskTestGeneratePdf('doc-1');
        $second = new TaskTestGeneratePdf('doc-2');

        $tasks->dispatch($first);
        $tasks->dispatch($second);

        $this->assertSame([$first, $second], $tasks->tasks());
    }

    public function testRecordingDispatcherRejectsNonInvocableTasks(): void
    {
        $tasks = new RecordingTaskDispatcher();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Task stdClass is not invocable');
        $tasks->dispatch(new stdClass());
    }

    public function testRunAllRunsInDispatchOrder(): void
    {
        $generator = new TaskTestPdfGenerator();
        $tasks = new RecordingTaskDispatcher();
        $tasks->dispatch(new TaskTestGeneratePdf('doc-1'));
        $tasks->dispatch(static fn(TaskTestPdfGenerator $generator) => $generator->generate('doc-2'));

        $tasks->runAll(new Injector(new Container([TaskTestPdfGenerator::class => $generator])));

        $this->assertSame(['doc-1', 'doc-2'], $generator->generated);
        $this->assertSame([], $tasks->tasks());
    }

    public function testRunAllRunsTasksDispatchedByRunningTasks(): void
    {
        $generator = new TaskTestPdfGenerator();
        $tasks = new RecordingTaskDispatcher();
        $injector = new Injector(new Container([
            TaskTestPdfGenerator::class => $generator,
            TaskDispatcher::class => $tasks,
        ]));
        $tasks->dispatch(new TaskTestGenerateThenArchive('doc-1'));
        $tasks->dispatch(new TaskTestGeneratePdf('doc-2'));

        $tasks->runAll($injector);

        $this->assertSame(['doc-1', 'doc-2', 'doc-1-archive'], $generator->generated);
        $this->assertSame([], $tasks->tasks());
    }

    public function testRunAllStopsOnFailureAndKeepsFollowingTasks(): void
    {
        $generator = new TaskTestPdfGenerator();
        $tasks = new RecordingTaskDispatcher();
        $following = new TaskTestGeneratePdf('doc-2');
        $tasks->dispatch(new TaskTestGeneratePdf('broken'));
        $tasks->dispatch($following);

        try {
            $tasks->runAll(new Injector(new Container([TaskTestPdfGenerator::class => $generator])));
            $this->fail('The failure should propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('Generation failed', $e->getMessage());
        }

        $this->assertSame([], $generator->generated);
        $this->assertSame([$following], $tasks->tasks());
    }
}
