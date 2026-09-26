<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability;

use Exception;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Observability\EventDispatcher;
use NeuronAI\Observability\ListenerRegistry;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Tests\Observability\Stub\CustomTestEvent;
use NeuronAI\Tests\Observability\Stub\EmittingNode;
use NeuronAI\Tests\Observability\Stub\RecordingDispatcher;
use NeuronAI\Tests\Observability\Stub\StoppableTestEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\RecordingObserver;
use NeuronAI\Tests\Workflow\Stub\InterruptableNode;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Observability\WorkflowError;
use NeuronAI\Workflow\Observability\WorkflowInterrupted;
use NeuronAI\Workflow\Observability\WorkflowNodeStart;
use NeuronAI\Workflow\Observability\WorkflowStart;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use UnnamespacedEvent;

use function array_column;
use function count;

class EventDispatcherTest extends TestCase
{
    /**
     * @return \NeuronAI\Workflow\NodeInterface[]
     */
    protected function linearNodes(): array
    {
        return [new NodeOne(), new NodeTwo(), new NodeThree()];
    }

    public function test_subscribe_receives_class_keyed_events(): void
    {
        $received = [];

        $workflow = Workflow::make('test-workflow')
            ->addNodes($this->linearNodes())
            ->subscribe(WorkflowStart::class, function (WorkflowStart $event) use (&$received): void {
                $received[] = $event;
            });

        $workflow->run();

        $this->assertCount(1, $received);
        $this->assertSame($workflow, $received[0]->source);
        $this->assertNull($received[0]->branchId);
    }

    public function test_workflow_event_catch_all_receives_full_lifecycle(): void
    {
        $names = [];

        $workflow = Workflow::make('test-workflow')
            ->addNodes($this->linearNodes())
            ->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event) use (&$names): void {
                $names[] = $event->name();
            });

        $workflow->run();

        $this->assertSame([
            'workflow-start',
            'workflow-node-start',
            'workflow-node-end',
            'workflow-node-start',
            'workflow-node-end',
            'workflow-node-start',
            'workflow-node-end',
            'workflow-end',
        ], $names);
    }

    public function test_legacy_observer_keeps_working_through_adapter(): void
    {
        $observer = new RecordingObserver();

        $workflow = Workflow::make('test-workflow')
            ->addNodes($this->linearNodes())
            ->observe($observer);

        $workflow->run();

        $names = array_column($observer->recorded, 'event');
        $this->assertSame('workflow-start', $names[0]);
        $this->assertSame('workflow-end', $names[count($names) - 1]);
        $this->assertCount(8, $names);

        // Outside branches the legacy contract reports the '__main__' branch.
        foreach ($observer->recorded as $record) {
            $this->assertSame('__main__', $record['branchId']);
        }
    }

    public function test_node_emit_stamps_source_and_dispatches_to_subscribers(): void
    {
        $received = [];

        $workflow = Workflow::make('test-workflow')
            ->addNodes([new EmittingNode()])
            ->subscribe(CustomTestEvent::class, function (CustomTestEvent $event) use (&$received): void {
                $received[] = $event;
            });

        $workflow->run();

        $this->assertCount(1, $received);
        $this->assertSame('emitted-from-node', $received[0]->value);
        $this->assertInstanceOf(EmittingNode::class, $received[0]->source);
        $this->assertNull($received[0]->branchId);
    }

    public function test_events_are_isolated_per_workflow_instance(): void
    {
        $first = [];
        $second = [];

        $workflowA = Workflow::make('test-workflow')
            ->addNodes($this->linearNodes())
            ->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event) use (&$first): void {
                $first[] = $event;
            });

        Workflow::make('test-workflow')
            ->addNodes($this->linearNodes())
            ->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event) use (&$second): void {
                $second[] = $event;
            });

        $workflowA->run();

        $this->assertCount(8, $first);
        $this->assertSame([], $second);
    }

    public function test_listeners_survive_multiple_runs_of_the_same_instance(): void
    {
        $starts = 0;

        $workflow = Workflow::make('test-workflow')
            ->addNodes($this->linearNodes())
            ->subscribe(WorkflowStart::class, function () use (&$starts): void {
                $starts++;
            });

        $workflow->run();
        $workflow->run();

        $this->assertSame(2, $starts);
    }

    public function test_external_psr_dispatcher_receives_forwarded_events(): void
    {
        $external = new RecordingDispatcher();

        $local = [];

        $workflow = Workflow::make('test-workflow')
            ->addNodes($this->linearNodes())
            ->setEventDispatcher($external)
            ->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event) use (&$local): void {
                $local[] = $event;
            });

        $workflow->run();

        $this->assertInstanceOf(WorkflowStart::class, $external->events[0]);
        // Local subscribers see the very same events, in the same order.
        $this->assertSame($local, $external->events);
    }

    public function test_interruption_dispatches_dedicated_event_not_workflow_error(): void
    {
        $interrupted = [];
        $errors = [];

        $workflow = Workflow::make('test-workflow')
            ->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()])
            ->subscribe(WorkflowInterrupted::class, function (WorkflowInterrupted $event) use (&$interrupted): void {
                $interrupted[] = $event;
            })
            ->subscribe(WorkflowError::class, function (WorkflowError $event) use (&$errors): void {
                $errors[] = $event;
            });

        $state = $workflow->run();

        $this->assertTrue($state->isInterrupted());
        $this->assertCount(1, $interrupted);
        $this->assertEquals($state, $interrupted[0]->state);
        $this->assertInstanceOf(ApprovalRequest::class, $interrupted[0]->state->getInterruptRequest());
        $this->assertSame($workflow, $interrupted[0]->source);
        $this->assertSame('workflow-interrupted', $interrupted[0]->name());
        $this->assertSame([], $errors);

        // Resuming to completion fires no further interruption event.
        $state = $workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['approved' => true]));

        $this->assertFalse($state->isInterrupted());
        $this->assertCount(1, $interrupted);
        $this->assertSame([], $errors);
    }

    public function test_event_name_derivation_and_overrides(): void
    {
        $this->assertSame('workflow-node-start', (new WorkflowNodeStart(NodeOne::class, new WorkflowState()))->name());
        $this->assertSame('workflow-end', (new WorkflowEnd(new WorkflowState()))->name());
        $this->assertSame('error', (new WorkflowError(new Exception('boom')))->name());
    }

    public function test_event_name_derivation_without_a_namespace(): void
    {
        require_once __DIR__ . '/Stub/UnnamespacedEvent.php';

        $this->assertSame('unnamespaced-event', (new UnnamespacedEvent())->name());
    }

    public function test_dispatch_runs_listeners_in_order_and_returns_the_event(): void
    {
        $calls = [];
        $registry = new ListenerRegistry();
        $registry->listen(CustomTestEvent::class, function (CustomTestEvent $event) use (&$calls): void {
            $calls[] = ['first', $event];
        });
        $registry->listen(CustomTestEvent::class, function (CustomTestEvent $event) use (&$calls): void {
            $calls[] = ['second', $event];
        });
        $event = new CustomTestEvent('value');

        $this->assertSame($event, (new EventDispatcher($registry))->dispatch($event));
        $this->assertSame([['first', $event], ['second', $event]], $calls);
    }

    public function test_forwarding_returns_what_the_external_dispatcher_returns(): void
    {
        $replacement = new CustomTestEvent('replaced');
        $external = new RecordingDispatcher($replacement);
        $event = new CustomTestEvent('value');

        $this->assertSame($replacement, (new EventDispatcher(new ListenerRegistry(), $external))->dispatch($event));
        $this->assertSame([$event], $external->events);
    }

    public function test_stopped_propagation_skips_the_remaining_listeners_and_the_forward(): void
    {
        $calls = [];
        $registry = new ListenerRegistry();
        $registry->listen(StoppableTestEvent::class, function (StoppableTestEvent $event) use (&$calls): void {
            $calls[] = 'stopper';
            $event->stopPropagation();
        });
        $registry->listen(StoppableTestEvent::class, function () use (&$calls): void {
            $calls[] = 'skipped';
        });
        $external = new RecordingDispatcher();
        $event = new StoppableTestEvent();

        $this->assertSame($event, (new EventDispatcher($registry, $external))->dispatch($event));
        $this->assertSame(['stopper'], $calls);
        $this->assertSame([], $external->events);
    }

    public function test_the_last_listener_stopping_propagation_skips_the_forward(): void
    {
        $registry = new ListenerRegistry();
        $registry->listen(StoppableTestEvent::class, static function (StoppableTestEvent $event): void {
            $event->stopPropagation();
        });
        $external = new RecordingDispatcher();

        (new EventDispatcher($registry, $external))->dispatch(new StoppableTestEvent());

        $this->assertSame([], $external->events);
    }

    public function test_an_already_stopped_event_reaches_no_listener(): void
    {
        $registry = new ListenerRegistry();
        $registry->listen(StoppableTestEvent::class, function (): void {
            $this->fail('A stopped event must not reach listeners.');
        });
        $external = new RecordingDispatcher();
        $event = new StoppableTestEvent();
        $event->stopPropagation();

        $this->assertSame($event, (new EventDispatcher($registry, $external))->dispatch($event));
        $this->assertSame([], $external->events);
    }

    public function test_a_listener_exception_propagates_and_stops_the_dispatch(): void
    {
        $failure = new Exception('listener failed');
        $registry = new ListenerRegistry();
        $registry->listen(CustomTestEvent::class, static function () use ($failure): void {
            throw $failure;
        });
        $registry->listen(CustomTestEvent::class, function (): void {
            $this->fail('Listeners after a failure must not run.');
        });
        $external = new RecordingDispatcher();

        try {
            (new EventDispatcher($registry, $external))->dispatch(new CustomTestEvent('value'));
            $this->fail('Expected the listener exception.');
        } catch (Exception $caught) {
            $this->assertSame($failure, $caught);
        }

        $this->assertSame([], $external->events);
    }
}
