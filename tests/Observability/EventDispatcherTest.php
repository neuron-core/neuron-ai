<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability;

use Exception;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowInterrupted;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Tests\Observability\Stub\CustomTestEvent;
use NeuronAI\Tests\Observability\Stub\EmittingNode;
use NeuronAI\Tests\Workflow\Executor\Stub\RecordingObserver;
use NeuronAI\Tests\Workflow\Stub\InterruptableNode;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function array_map;
use function count;
use function in_array;

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

        $workflow = Workflow::make()
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

        $workflow = Workflow::make()
            ->addNodes($this->linearNodes())
            ->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event) use (&$names): void {
                $names[] = $event->name();
            });

        $workflow->run();

        $this->assertSame('workflow-start', $names[0]);
        $this->assertSame('workflow-end', $names[count($names) - 1]);
        $this->assertContains('workflow-node-start', $names);
        $this->assertContains('workflow-node-end', $names);
    }

    public function test_legacy_observer_keeps_working_through_adapter(): void
    {
        $observer = new RecordingObserver();

        $workflow = Workflow::make()
            ->addNodes($this->linearNodes())
            ->observe($observer);

        $workflow->run();

        $names = array_column($observer->recorded, 'event');
        $this->assertContains('workflow-start', $names);
        $this->assertContains('workflow-node-start', $names);
        $this->assertContains('workflow-end', $names);

        // Outside branches the legacy contract reports the '__main__' branch.
        foreach ($observer->recorded as $record) {
            $this->assertSame('__main__', $record['branchId']);
        }
    }

    public function test_node_emit_stamps_source_and_dispatches_to_subscribers(): void
    {
        $received = [];

        $workflow = Workflow::make()
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

        $workflowA = Workflow::make()
            ->addNodes($this->linearNodes())
            ->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event) use (&$first): void {
                $first[] = $event;
            });

        Workflow::make()
            ->addNodes($this->linearNodes())
            ->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event) use (&$second): void {
                $second[] = $event;
            });

        $workflowA->run();

        $this->assertNotEmpty($first);
        $this->assertSame([], $second);
    }

    public function test_listeners_survive_multiple_runs_of_the_same_instance(): void
    {
        $starts = 0;

        $workflow = Workflow::make()
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
        $external = new class () implements EventDispatcherInterface {
            /** @var object[] */
            public array $events = [];

            public function dispatch(object $event): object
            {
                $this->events[] = $event;
                return $event;
            }
        };

        $local = [];

        $workflow = Workflow::make()
            ->addNodes($this->linearNodes())
            ->setEventDispatcher($external)
            ->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event) use (&$local): void {
                $local[] = $event;
            });

        $workflow->run();

        $this->assertNotEmpty($external->events);
        $this->assertTrue(in_array(WorkflowStart::class, array_map(static fn (object $e): string => $e::class, $external->events), true));
        // Local subscribers keep working alongside the external dispatcher.
        $this->assertCount(count($external->events), $local);
    }

    public function test_interruption_dispatches_dedicated_event_not_agent_error(): void
    {
        $interrupted = [];
        $errors = [];

        $workflow = Workflow::make()
            ->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()])
            ->subscribe(WorkflowInterrupted::class, function (WorkflowInterrupted $event) use (&$interrupted): void {
                $interrupted[] = $event;
            })
            ->subscribe(AgentError::class, function (AgentError $event) use (&$errors): void {
                $errors[] = $event;
            });

        $state = $workflow->run();

        $this->assertTrue($state->isInterrupted());
        $this->assertCount(1, $interrupted);
        $this->assertSame($state, $interrupted[0]->state);
        $this->assertInstanceOf(ApprovalRequest::class, $interrupted[0]->state->getInterruptRequest());
        $this->assertSame($workflow, $interrupted[0]->source);
        $this->assertSame('workflow-interrupted', $interrupted[0]->name());
        $this->assertSame([], $errors);

        // Resuming to completion fires no further interruption event.
        $state = $workflow->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['approved' => true])]);

        $this->assertFalse($state->isInterrupted());
        $this->assertCount(1, $interrupted);
        $this->assertSame([], $errors);
    }

    public function test_event_name_derivation_and_overrides(): void
    {
        $this->assertSame('workflow-node-start', (new WorkflowNodeStart(NodeOne::class, new WorkflowState()))->name());
        $this->assertSame('workflow-end', (new WorkflowEnd(new WorkflowState()))->name());
        $this->assertSame('error', (new AgentError(new Exception('boom')))->name());
    }
}
