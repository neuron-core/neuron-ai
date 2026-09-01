<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stub\ConditionalNode;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\InterruptableNode;
use NeuronAI\Tests\Workflow\Stub\NodeForSecond;
use NeuronAI\Tests\Workflow\Stub\NodeForThird;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

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

    public function test_node_class_string_instantiation(): void
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
    }

    public function test_event_node_map_building(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $this->execute($workflow);
        $eventNodeMap = $workflow->getEventNodeMap();

        $this->assertArrayHasKey(StartEvent::class, $eventNodeMap);
        $this->assertArrayHasKey(FirstEvent::class, $eventNodeMap);
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

    public function test_workflow_validation_fails_with_no_start_node(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle ' . StartEvent::class);

        $workflow = Workflow::make()
            ->addNodes([
                new NodeTwo(),
                new NodeThree(),
            ]);

        $this->execute($workflow);
    }

    public function test_workflow_fails_when_no_node_handles_event(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No node found that handle event');

        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                // Missing NodeTwo that handles FirstEvent
                new NodeThree(),
            ]);

        $this->execute($workflow);
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

    public function test_identity_is_assigned_by_the_executor(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new InterruptableNode(),
                new NodeThree(),
            ]);

        // Identity is assigned by the executor's identity phase, never
        // defaulted at construction.
        $this->assertNull($workflow->getWorkflowId());
        $this->assertNull($workflow->getRunId());

        $this->execute($workflow, new InMemoryPersistence());

        $this->assertNotEmpty($workflow->getWorkflowId());
        $this->assertStringStartsWith('workflow_', (string) $workflow->getWorkflowId());
        $this->assertNotEmpty($workflow->getRunId());
        $this->assertStringStartsWith('run_', (string) $workflow->getRunId());
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

        $request = $this->execute($workflow, $persistence)->getInterruptRequest();
        $token = $workflow->getWorkflowId();

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
}
