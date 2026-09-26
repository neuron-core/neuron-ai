<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use Generator;
use NeuronAI\Tests\Support\ExecutionTestFactory;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stub\ConditionalNode;
use NeuronAI\Tests\Workflow\Stub\ExposedNode;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\InterruptableNode;
use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Tests\Workflow\Stub\NodeForSecond;
use NeuronAI\Tests\Workflow\Stub\NodeForThird;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Tests\Workflow\Stub\SecondEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

use function array_map;
use function iterator_to_array;

class WorkflowTest extends TestCase
{
    use ExecutorTestHelpers;

    public function test_basic_linear_workflow_execution(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $finalState = $this->execute($workflow);
        $this->assertTrue($finalState->get('node_one_executed'));
        $this->assertTrue($finalState->get('node_two_executed'));
        $this->assertTrue($finalState->get('node_three_executed'));
        $this->assertEquals('First complete', $finalState->get('first_message'));
        $this->assertEquals('Second complete', $finalState->get('second_message'));
    }

    public function test_run_executes_the_workflow_synchronously(): void
    {
        // Drive through the public run() entry point (not the executor helper) to
        // ensure the lazy generator is actually consumed and the state returned.
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $finalState = $workflow->run();

        $this->assertTrue($finalState->get('node_one_executed'));
        $this->assertTrue($finalState->get('node_three_executed'));
        $this->assertFalse($finalState->isInterrupted());
        $this->assertSame(WorkflowStatus::Completed, $finalState->getStatus());
    }

    public function test_workflow_with_initial_state(): void
    {
        $workflow = Workflow::make(state: new WorkflowState(['initial_data' => 'test']))
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $finalState = $this->execute($workflow);

        $this->assertEquals('test', $finalState->get('initial_data'));
        $this->assertTrue($finalState->get('node_one_executed'));
    }

    public function test_closure_node_factories_build_a_fresh_node_for_every_execution(): void
    {
        $factory = new class () {
            /** @var NodeOne[] */
            public array $built = [];

            public function __invoke(): NodeOne
            {
                return $this->built[] = new NodeOne();
            }
        };
        $workflow = Workflow::make()->addNodes([$factory(...), new NodeTwo(), new NodeThree()]);

        $this->assertCount(0, $factory->built);

        $this->assertTrue($workflow->run()->get('node_one_executed'));
        $this->assertTrue($workflow->run()->get('node_one_executed'));

        $this->assertCount(2, $factory->built);
        $this->assertNotSame($factory->built[0], $factory->built[1]);
    }

    public function test_event_node_map_building(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $eventNodeMap = ExecutionTestFactory::graph($workflow)->nodes();

        $this->assertSame([
            StartEvent::class => NodeOne::class,
            FirstEvent::class => NodeTwo::class,
            SecondEvent::class => NodeThree::class,
        ], array_map(static fn (NodeInterface $node): string => $node::class, $eventNodeMap));
    }

    public function test_conditional_node_with_union_return_type(): void
    {
        $nodes = [
            new NodeOne(),
            new ConditionalNode(),
            new NodeForSecond(),
            new NodeForThird(),
        ];

        $workflow = Workflow::make(state: new WorkflowState(['condition' => 'second']))
            ->addNodes($nodes);

        $finalState = $this->execute($workflow);

        $this->assertTrue($finalState->get('conditional_node_executed'));
        $this->assertTrue($finalState->get('second_path_executed'));
        $this->assertFalse($finalState->has('third_path_executed'));
        $this->assertEquals('Conditional chose second', $finalState->get('final_second_message'));

        // Test the third path
        $workflow = Workflow::make(state: new WorkflowState(['condition' => 'third']))
            ->addNodes($nodes);
        $finalState = $this->execute($workflow);

        $this->assertTrue($finalState->get('conditional_node_executed'));
        $this->assertTrue($finalState->get('third_path_executed'));
        $this->assertFalse($finalState->has('second_path_executed'));
        $this->assertEquals('Conditional chose third', $finalState->get('final_third_message'));
    }

    public function test_workflow_interrupt(): void
    {
        $workflowId = 'test-workflow';

        $workflow = Workflow::make(workflowId: $workflowId)
            ->addNodes([
                new NodeOne(),
                new InterruptableNode(),
                new NodeThree(),
            ]);

        $state = $this->execute($workflow);

        // Paused: the interrupt request is surfaced on the state, and nodes after
        // the pausing one did not execute.
        $this->assertTrue($state->isInterrupted());
        $this->assertSame('human input needed', $state->getInterruptRequest()->getMessage());
        $this->assertFalse($state->has('node_three_executed'));
    }

    public function test_workflow_resume(): void
    {
        $workflowId = 'test-workflow';

        $workflow = Workflow::make(workflowId: $workflowId)
            ->addNodes([
                new NodeOne(),
                new InterruptableNode(),
                new NodeThree(),
            ]);

        $state = $this->execute($workflow);

        $this->assertTrue($state->isInterrupted());
        $this->assertSame('human input needed', $state->getInterruptRequest()->getMessage());
        // NodeOne ran; InterruptableNode paused before completing its own work
        $this->assertTrue($state->get('node_one_executed'));
        $this->assertNull($state->get('received_feedback'));

        // Resume delivers the payload through interrupt()
        $state = $this->resume($workflow);

        $this->assertFalse($state->isInterrupted());
        $this->assertTrue($state->get('interruptable_node_executed'));
        $this->assertSame('completed', $state->get('received_feedback'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function test_identity_is_assigned_on_first_execution(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new InterruptableNode(),
                new NodeThree(),
            ]);

        // Workflow establishes identity when execution starts.
        $this->assertNull($workflow->getWorkflowId());
        $this->assertNull($workflow->inspect()?->runId);

        $state = $this->execute($workflow, new InMemoryPersistence());

        $this->assertSame($state->getWorkflowId(), $workflow->getWorkflowId());
        $this->assertNotEmpty($state->getWorkflowId());
        $this->assertStringStartsWith('workflow_', $state->getWorkflowId());
        $this->assertNotEmpty($state->getRunId());
        $this->assertStringStartsWith('run_', $state->getRunId());
    }

    public function test_interrupt_state_is_resumable_from_token(): void
    {
        // Prove durability: resume on a fresh executor + fresh workflow instance,
        // sharing only the persistence and the resume token.
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new InterruptableNode(),
                new NodeThree(),
            ]);

        $state = $this->execute($workflow, $persistence);
        $request = $state->getInterruptRequest();
        $token = $state->getWorkflowId();

        $this->assertNotNull($request);
        $this->assertNotNull($token);

        $resumed = Workflow::make(workflowId: $token)
            ->addNodes([
                new NodeOne(),
                new InterruptableNode(),
                new NodeThree(),
            ]);

        $state = $this->resume($resumed, $persistence, []);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('completed', $state->get('received_feedback'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function test_make_builds_the_called_class_with_its_arguments(): void
    {
        $workflow = KeyedWorkflow::make('order-1', new WorkflowState(['seed' => 'value']));

        $this->assertInstanceOf(KeyedWorkflow::class, $workflow);
        $this->assertSame('order-1', $workflow->getWorkflowId());
        $this->assertSame('value', $workflow->run()->get('seed'));
    }

    public function test_configuration_methods_are_fluent_on_the_same_instance(): void
    {
        $workflow = Workflow::make();

        $this->assertSame($workflow, $workflow->addNode(new NodeOne()));
        $this->assertSame($workflow, $workflow->addNodes([new NodeTwo()]));
        $this->assertSame($workflow, $workflow->setStartEvent(new StartEvent()));
        $this->assertSame($workflow, $workflow->setState(new WorkflowState()));
        $this->assertSame($workflow, $workflow->setPersistence(new InMemoryPersistence()));
        $this->assertSame($workflow, $workflow->setLeaseTimeout(null));
        $this->assertSame($workflow, $workflow->retainCompletionUntilAcknowledged(false));
        $this->assertSame($workflow, $workflow->addGlobalMiddleware([]));
        $this->assertSame($workflow, $workflow->addMiddleware(NodeOne::class, []));
        $this->assertSame($workflow, $workflow->setWorkflowId('fluent'));
    }

    public function test_a_configured_start_event_routes_to_its_node(): void
    {
        $start = new FirstEvent('configured start');
        $workflow = Workflow::make()
            ->setStartEvent($start)
            ->addNodes([new NodeTwo(), new NodeThree()]);

        $state = $workflow->run();

        $this->assertSame($start, $workflow->getStartEvent());
        $this->assertSame('configured start', $state->get('first_message'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function test_the_start_event_hook_defines_the_default_start(): void
    {
        $workflow = new class () extends Workflow {
            protected function startEvent(): FirstEvent
            {
                return new FirstEvent('hook start');
            }

            protected function nodes(): array
            {
                return [new NodeTwo(), new NodeThree()];
            }
        };

        $this->assertInstanceOf(FirstEvent::class, $workflow->getStartEvent());
        $this->assertSame('hook start', $workflow->run()->get('first_message'));
    }

    public function test_a_start_request_without_an_event_uses_the_configured_start_event(): void
    {
        $workflow = Workflow::make()
            ->setStartEvent(new FirstEvent('configured start'))
            ->addNodes([new NodeTwo(), new NodeThree()]);

        $state = $workflow->run(ExecutionRequest::start(runId: 'reserved'));

        $this->assertSame('reserved', $state->getRunId());
        $this->assertSame('configured start', $state->get('first_message'));
    }

    public function test_the_nodes_hook_is_rebuilt_for_every_segment(): void
    {
        $workflow = new class () extends Workflow {
            /** @var array<NodeInterface[]> */
            public array $graphs = [];

            protected function nodes(): array
            {
                return $this->graphs[] = [new NodeOne(), new InterruptableNode(), new NodeThree()];
            }
        };

        $this->assertTrue($workflow->run()->isInterrupted());
        $this->assertCount(1, $workflow->graphs);

        $this->assertFalse($workflow->run(ExecutionRequest::resume([]))->isInterrupted());
        $this->assertCount(2, $workflow->graphs);
        $this->assertNotSame($workflow->graphs[0][0], $workflow->graphs[1][0]);
    }

    public function test_hook_nodes_and_added_nodes_form_one_graph(): void
    {
        $workflow = new class () extends Workflow {
            protected function nodes(): array
            {
                return [new NodeOne()];
            }
        };
        $workflow->addNodes([new NodeTwo(), new NodeThree()]);

        $state = $workflow->run();

        $this->assertTrue($state->get('node_one_executed'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function test_an_added_node_cannot_shadow_a_hook_node_for_the_same_event(): void
    {
        $workflow = new class () extends Workflow {
            protected function nodes(): array
            {
                return [new NodeOne()];
            }
        };
        $workflow->addNode(new ExposedNode());

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Node for event ' . StartEvent::class . ' already exists');

        $workflow->run();
    }

    public function test_events_is_lazy_until_iterated(): void
    {
        $node = new ExposedNode();
        $executions = 0;
        $workflow = Workflow::make()->addNode(function () use ($node, &$executions): NodeInterface {
            $executions++;
            return $node;
        });

        $events = $workflow->events();

        $this->assertInstanceOf(Generator::class, $events);
        $this->assertSame(0, $executions);
        $this->assertNull($workflow->getWorkflowId());
        $this->assertNull($workflow->inspect());

        iterator_to_array($events);

        $this->assertSame(1, $executions);
        $this->assertSame(WorkflowStatus::Completed, $events->getReturn()->getStatus());
        $this->assertSame($workflow->getWorkflowId(), $events->getReturn()->getWorkflowId());
    }

    public function test_an_unreachable_node_is_accepted_but_never_executed(): void
    {
        $workflow = Workflow::make()->addNodes([
            new NodeOne(),
            new NodeTwo(),
            new NodeThree(),
            new NodeForThird(),
        ]);

        $state = $workflow->run();

        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertFalse($state->has('third_path_executed'));
    }
}
