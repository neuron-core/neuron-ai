<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Tests\Workflow\Stub\SecondEvent;
use NeuronAI\Tests\Workflow\Stub\WaitForEventNode;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Observability\BranchEnd;
use NeuronAI\Workflow\Observability\BranchStart;
use NeuronAI\Workflow\Observability\ChannelError;
use NeuronAI\Workflow\Observability\MiddlewareEnd;
use NeuronAI\Workflow\Observability\MiddlewareStart;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Observability\WorkflowError;
use NeuronAI\Workflow\Observability\WorkflowInterrupted;
use NeuronAI\Workflow\Observability\WorkflowNodeEnd;
use NeuronAI\Workflow\Observability\WorkflowNodeStart;
use NeuronAI\Workflow\Observability\WorkflowStart;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_diff_key;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;

/**
 * Listeners and loggers pair what a run reports: the order, the source and
 * the payload of every lifecycle event are a contract.
 */
class WorkflowObservabilityTest extends TestCase
{
    /** @var ObservabilityEvent[] */
    protected array $events = [];

    protected function observed(Workflow $workflow): Workflow
    {
        return $workflow->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event): void {
            $this->events[] = $event;
        });
    }

    /** @return string[] */
    protected function names(): array
    {
        return array_map(static fn (ObservabilityEvent $event): string => $event->name(), $this->events);
    }

    /**
     * Whether a node that suspends or fails should still report its end is an
     * open design question, so these sequences leave node ends out.
     *
     * @return string[]
     */
    protected function namesWithoutNodeEnds(): array
    {
        return array_values(array_filter($this->names(), static fn (string $name): bool => $name !== 'workflow-node-end'));
    }

    public function test_a_completed_run_reports_its_lifecycle_in_order(): void
    {
        $workflow = $this->observed(
            Workflow::make('observed')
                ->addMiddleware(NodeThree::class, FakeMiddleware::make())
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $workflow->run();

        $this->assertSame([
            'workflow-start',
            'workflow-node-start', 'workflow-node-end',
            'workflow-node-start', 'workflow-node-end',
            'workflow-node-start',
            'middleware-before-start', 'middleware-before-end',
            'middleware-after-start', 'middleware-after-end',
            'workflow-node-end',
            'workflow-end',
        ], $this->names());
    }

    public function test_node_and_middleware_events_come_from_the_node_and_the_others_from_the_workflow(): void
    {
        $workflow = $this->observed(
            Workflow::make('observed')
                ->addGlobalMiddleware(FakeMiddleware::make())
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $workflow->run();

        foreach ($this->events as $event) {
            $nodeScoped = $event instanceof WorkflowNodeStart || $event instanceof WorkflowNodeEnd
                || $event instanceof MiddlewareStart || $event instanceof MiddlewareEnd;
            if ($nodeScoped) {
                $this->assertInstanceOf(Node::class, $event->source, $event->name());
            } else {
                $this->assertSame($workflow, $event->source, $event->name());
            }
            $this->assertNull($event->branchId, $event->name());
        }
        $nodeStarts = array_values(array_filter($this->events, static fn (ObservabilityEvent $event): bool => $event instanceof WorkflowNodeStart));
        $this->assertSame(
            [NodeOne::class, NodeTwo::class, NodeThree::class],
            array_map(static fn (ObservabilityEvent $event): string => $event->source::class, $nodeStarts),
        );
    }

    public function test_every_event_carries_the_execution_of_its_segment(): void
    {
        $workflow = $this->observed(Workflow::make('observed')->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]));

        $state = $workflow->run();

        $this->assertNotEmpty($this->events);
        foreach ($this->events as $event) {
            $this->assertNotNull($event->execution, $event->name());
            $this->assertSame('observed', $event->execution->workflowId);
            $this->assertSame($state->getRunId(), $event->execution->runId);
            $this->assertSame($state->getExecutionAttempt(), $event->execution->executionAttempt);
        }
    }

    public function test_lifecycle_payloads(): void
    {
        $middleware = FakeMiddleware::make();
        $workflow = $this->observed(
            Workflow::make('observed')
                ->addMiddleware(NodeThree::class, $middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $state = $workflow->run();

        [$start, , , , , $nodeStart, $beforeStart, $beforeEnd, $afterStart, $afterEnd, $nodeEnd, $end] = $this->events;
        $this->assertInstanceOf(WorkflowStart::class, $start);
        $this->assertSame([
            StartEvent::class => NodeOne::class,
            FirstEvent::class => NodeTwo::class,
            SecondEvent::class => NodeThree::class,
        ], $start->toArray());
        $this->assertInstanceOf(WorkflowNodeStart::class, $nodeStart);
        $this->assertSame(['node' => NodeThree::class], $nodeStart->toArray());
        $this->assertSame(['class' => FakeMiddleware::class, 'node-event' => SecondEvent::class], $beforeStart->toArray());
        $this->assertSame(['class' => FakeMiddleware::class], $beforeEnd->toArray());
        $this->assertSame(['class' => FakeMiddleware::class, 'node-event' => StopEvent::class], $afterStart->toArray());
        $this->assertSame(['class' => FakeMiddleware::class], $afterEnd->toArray());
        $this->assertInstanceOf(WorkflowNodeEnd::class, $nodeEnd);
        $this->assertSame(['node' => NodeThree::class], $nodeEnd->toArray());
        $this->assertInstanceOf(WorkflowEnd::class, $end);
        // Only the identity is pinned: whether the whole state belongs in every log record is an open design question.
        $this->assertSame([
            'workflowId' => 'observed',
            'runId' => $state->getRunId(),
            'executionAttempt' => 1,
            'status' => 'completed',
        ], array_diff_key($end->toArray(), ['state' => true]));
    }

    public function test_a_suspended_run_reports_its_interruption_before_the_end(): void
    {
        $workflow = $this->observed(Workflow::make('observed')->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]));

        $state = $workflow->run();

        $this->assertSame([
            'workflow-start',
            'workflow-node-start',
            'workflow-node-start',
            'workflow-interrupted',
            'workflow-end',
        ], $this->namesWithoutNodeEnds());
        [$interrupted, $end] = array_slice($this->events, -2);
        $this->assertInstanceOf(WorkflowInterrupted::class, $interrupted);
        $this->assertSame([
            'workflowId' => 'observed',
            'runId' => $state->getRunId(),
            'executionAttempt' => 1,
            'status' => 'suspended',
            'interrupt' => ['interruptId' => 1, 'type' => 'wait_for_event', 'eventName' => 'user.signup', 'expiresAt' => null],
        ], $interrupted->toArray());
        $this->assertInstanceOf(WorkflowEnd::class, $end);
        $this->assertSame('suspended', $end->toArray()['status']);
    }

    public function test_a_failed_run_reports_the_original_exception_without_its_trace(): void
    {
        $failure = new RuntimeException('node exploded');
        $workflow = $this->observed(Workflow::make('observed')->addNode(new class ($failure) extends Node {
            public function __construct(protected RuntimeException $failure)
            {
            }

            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                throw $this->failure;
            }
        }));

        try {
            $workflow->run();
            $this->fail('The node failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame(['workflow-start', 'workflow-node-start', 'error', 'workflow-end'], $this->namesWithoutNodeEnds());
        [$error, $end] = array_slice($this->events, -2);
        $this->assertInstanceOf(WorkflowError::class, $error);
        $this->assertSame($failure, $error->exception);
        $this->assertFalse($error->unhandled);
        $this->assertSame($workflow, $error->source);
        $this->assertSame(['error' => 'node exploded'], $error->toArray());
        $this->assertInstanceOf(WorkflowEnd::class, $end);
        $this->assertSame('failed', $end->toArray()['status']);
    }

    /** @return iterable<string, array{ObservabilityEvent, string}> */
    public static function eventNames(): iterable
    {
        $state = new WorkflowState();
        $middleware = FakeMiddleware::make();
        yield 'workflow start' => [new WorkflowStart([]), 'workflow-start'];
        yield 'workflow end' => [new WorkflowEnd($state), 'workflow-end'];
        yield 'workflow interrupted' => [new WorkflowInterrupted($state), 'workflow-interrupted'];
        yield 'workflow error' => [new WorkflowError(new RuntimeException()), 'error'];
        yield 'node start' => [new WorkflowNodeStart(NodeOne::class, $state), 'workflow-node-start'];
        yield 'node end' => [new WorkflowNodeEnd(NodeOne::class, $state), 'workflow-node-end'];
        yield 'middleware start' => [new MiddlewareStart($middleware, new StartEvent()), 'middleware-before-start'];
        yield 'middleware end' => [new MiddlewareEnd($middleware, 'after'), 'middleware-after-end'];
        yield 'branch start' => [new BranchStart('left'), 'branch-start'];
        yield 'branch end' => [new BranchEnd('left'), 'branch-end'];
        yield 'channel error' => [new ChannelError(new RuntimeException()), 'channel-error'];
    }

    #[DataProvider('eventNames')]
    public function test_event_names_are_stable(ObservabilityEvent $event, string $name): void
    {
        $this->assertSame($name, $event->name());
    }

    public function test_branch_events_carry_their_branch(): void
    {
        $this->assertSame('left', (new BranchStart('left'))->branchId);
        $this->assertSame('right', (new BranchEnd('right'))->branchId);
    }

    public function test_an_interrupted_state_without_a_request_reports_a_null_interrupt(): void
    {
        $state = new WorkflowState();
        $state->markAsSuspended(null);

        $this->assertNull((new WorkflowInterrupted($state))->toArray()['interrupt']);
        $this->assertSame(WorkflowStatus::Suspended->value, (new WorkflowInterrupted($state))->toArray()['status']);
    }
}
