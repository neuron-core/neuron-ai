<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use Closure;
use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\SegmentEventDispatcher;
use NeuronAI\Workflow\Observability\BranchStart;
use NeuronAI\Workflow\Observability\WorkflowError;
use NeuronAI\Workflow\Observability\WorkflowStart;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use stdClass;

class SegmentEventDispatcherTest extends TestCase
{
    protected ExecutionContext $context;

    protected stdClass $workflow;

    /** @var list<object> */
    protected array $dispatched = [];

    protected function setUp(): void
    {
        $this->context = new ExecutionContext('workflow-1', 'run-1', 2, new Ignition('run-1', new StartEvent()));
        $this->workflow = new stdClass();
        $this->dispatched = [];
    }

    /** @param Closure(object): void $listener */
    protected function dispatcher(Closure $listener): SegmentEventDispatcher
    {
        $inner = new class ($listener) implements EventDispatcherInterface {
            /** @param Closure(object): void $listener */
            public function __construct(protected Closure $listener)
            {
            }

            public function dispatch(object $event): object
            {
                ($this->listener)($event);

                return $event;
            }
        };

        return new SegmentEventDispatcher($inner, $this->context, $this->workflow);
    }

    protected function recording(): SegmentEventDispatcher
    {
        return $this->dispatcher(function (object $event): void {
            $this->dispatched[] = $event;
        });
    }

    public function test_events_carry_the_segment_context_and_default_to_the_workflow_source(): void
    {
        $event = new WorkflowStart([]);

        $this->recording()->dispatch($event);

        $this->assertSame([$event], $this->dispatched);
        $this->assertSame($this->context, $event->execution);
        $this->assertSame($this->workflow, $event->source);
    }

    public function test_an_event_keeps_the_source_that_emitted_it(): void
    {
        $node = new stdClass();
        $event = new BranchStart('text');
        $event->source = $node;

        $this->recording()->dispatch($event);

        $this->assertSame($node, $event->source);
        $this->assertSame('text', $event->branchId);
        $this->assertSame($this->context, $event->execution);
    }

    public function test_foreign_events_pass_through_untouched(): void
    {
        $event = new ChunkEvent('payload');

        $returned = $this->recording()->dispatch($event);

        $this->assertSame($event, $returned);
        $this->assertSame([$event], $this->dispatched);
    }

    public function test_a_failing_listener_is_reported_as_an_error_of_the_same_origin(): void
    {
        $failure = new RuntimeException('listener failed');
        $dispatcher = $this->dispatcher(function (object $event) use ($failure): void {
            $this->dispatched[] = $event;
            if ($event instanceof BranchStart) {
                throw $failure;
            }
        });
        $node = new stdClass();
        $event = new BranchStart('image');
        $event->source = $node;

        $dispatcher->report($event);

        $this->assertCount(2, $this->dispatched);
        $error = $this->dispatched[1];
        $this->assertInstanceOf(WorkflowError::class, $error);
        $this->assertSame($failure, $error->exception);
        $this->assertFalse($error->unhandled);
        $this->assertSame($node, $error->source);
        $this->assertSame('image', $error->branchId);
        $this->assertSame($this->context, $error->execution);
    }

    public function test_a_listener_failing_on_errors_too_is_dropped_without_recursion(): void
    {
        $calls = 0;
        $dispatcher = $this->dispatcher(function (object $event) use (&$calls): void {
            $calls++;
            throw new RuntimeException('always failing');
        });

        $dispatcher->report(new WorkflowStart([]));

        $this->assertSame(2, $calls);
    }

    public function test_dispatch_does_not_swallow_listener_failures(): void
    {
        $dispatcher = $this->dispatcher(function (object $event): void {
            throw new RuntimeException('node emission failed');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('node emission failed');

        $dispatcher->dispatch(new WorkflowStart([]));
    }
}
