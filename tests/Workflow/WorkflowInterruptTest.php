<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Stubs\CustomState;
use NeuronAI\Tests\Workflow\Stubs\MultipleInterruptionsNode;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;

class WorkflowInterruptTest extends TestCase
{
    public function testWorkflowInterruptException(): void
    {
        $this->expectException(WorkflowInterrupt::class);
        $this->expectExceptionMessage('Workflow interrupted for human input');

        $workflow = Workflow::make(
            persistence: new InMemoryPersistence(),
            workflowId: 'test-workflow'
        )->addNodes([
            new NodeOne(),
            new InterruptableNode(),
             new NodeThree(),
        ]);

        $workflow->start()->getResult();
    }

    public function testWorkflowInterruptContainsData(): void
    {
        $workflow = Workflow::make(
            persistence: new InMemoryPersistence(),
            workflowId: 'test-workflow'
        )->addNodes([
            new NodeOne(),
            new InterruptableNode(),
             new NodeThree(),
        ]);

        try {
            $workflow->start()->getResult();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['message' => 'Need human input'], $interrupt->getData());
            $this->assertInstanceOf(InterruptableNode::class, $interrupt->getCurrentNode());
            $this->assertInstanceOf(WorkflowState::class, $interrupt->getState());
        }
    }

    public function testWorkflowInterruptPreservesState(): void
    {
        $workflow = Workflow::make(
            new WorkflowState(['initial_data' => 'preserved']),
            new InMemoryPersistence(),
            'test-workflow'
        )->addNodes([
            new NodeOne(),
            new InterruptableNode(),
            new NodeThree(),
        ]);

        try {
            $workflow->start()->getResult();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (WorkflowInterrupt $interrupt) {
            $state = $interrupt->getState();

            // Verify initial data is preserved
            $this->assertEquals('preserved', $state->get('initial_data'));

            // Verify nodes up to interrupt point were executed
            $this->assertTrue($state->get('node_one_executed'));
            $this->assertTrue($state->get('interruptable_node_executed'));

            // Verify node after interrupt was not executed
            $this->assertFalse($state->has('node_three_executed'));
        }
    }

    public function testWorkflowInterruptContainsCurrentEvent(): void
    {
        $workflow = Workflow::make(
            persistence: new InMemoryPersistence(),
            workflowId: 'test-workflow'
        )->addNodes([
            new NodeOne(),
            new InterruptableNode(),
            new NodeThree(),
        ]);

        try {
            $workflow->start()->getResult();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (WorkflowInterrupt $interrupt) {
            $currentEvent = $interrupt->getCurrentEvent();

            $this->assertInstanceOf(\NeuronAI\Tests\Workflow\Stubs\FirstEvent::class, $currentEvent);
            $this->assertEquals('First complete', $currentEvent->message);
        }
    }

    public function testWorkflowResume(): void
    {
        $workflow = Workflow::make(
            persistence: new InMemoryPersistence(),
            workflowId: 'test-workflow'
        )->addNodes([
            new NodeOne(),
            new InterruptableNode(),
            new NodeThree(),
        ]);

        // First run - should interrupt
        try {
            $workflow->start()->getResult();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (WorkflowInterrupt $interrupt) {
            // Verify interrupt occurred at correct node
            $this->assertInstanceOf(InterruptableNode::class, $interrupt->getCurrentNode());
        }

        // Resume with human feedback
        $finalState = $workflow->start(true, 'human feedback provided')->getResult();

        // Verify the workflow completed successfully
        $this->assertTrue($finalState->get('node_one_executed'));
        $this->assertTrue($finalState->get('interruptable_node_executed'));
        $this->assertTrue($finalState->get('node_three_executed'));

        // Verify human feedback was processed
        $this->assertEquals('human feedback provided', $finalState->get('received_feedback'));
    }

    public function testWorkflowResumeWithComplexFeedback(): void
    {
        $workflow = Workflow::make(
            persistence: new InMemoryPersistence(),
            workflowId: 'test-workflow'
        )->addNodes([
            new NodeOne(),
            new InterruptableNode(),
            new NodeThree(),
        ]);

        try {
            $workflow->start()->getResult();
        } catch (WorkflowInterrupt) {
            // Expected
        }

        // Resume with array feedback
        $complexFeedback = ['decision' => 'approve', 'priority' => 'high'];
        $finalState = $workflow->start(true, $complexFeedback)->getResult();

        $this->assertEquals($complexFeedback, $finalState->get('received_feedback'));
    }

    public function testWorkflowResumeWithoutPriorInterrupt(): void
    {
        $workflow = Workflow::make(
            persistence: new InMemoryPersistence(),
            workflowId: 'empty-workflow'
        );

        $this->expectException(\Exception::class); // Should fail when trying to load non-existent interrupt

        $workflow->start(true, 'feedback')->getResult();
    }

    public function testMultipleInterruptsAndResumes(): void
    {
        $workflow = Workflow::make(
            persistence: new InMemoryPersistence(),
            workflowId: 'multi-interrupt-workflow'
        )->addNodes([
            new NodeOne(),
            new MultipleInterruptionsNode(),
            new NodeThree(),
        ]);

        // First interrupt
        try {
            $workflow->start()->getResult();
            $this->fail('First interrupt not thrown');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['count' => 1, 'message' => 'Interrupt #1'], $interrupt->getData());
        }

        // Second interrupt
        try {
            $finalState = $workflow->start(true, 'second interrupt')->getResult();
            $this->fail('Second interrupt not thrown');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['count' => 2, 'message' => 'Interrupt #2'], $interrupt->getData());
        }

        // Final completion
        $finalState = $workflow->start(true, 'second response')->getResult();
        $this->assertTrue($finalState->get('all_interrupts_complete'));
        $this->assertEquals(3, $finalState->get('interrupt_count'));
    }

    public function testWorkflowInterruptSerialization(): void
    {
        $workflow = Workflow::make(
            persistence: new InMemoryPersistence(),
            workflowId: 'serialize-workflow'
        )->addNodes([
            new NodeOne(),
            new InterruptableNode(),
        ]);

        try {
            $workflow->start()->getResult();
        } catch (WorkflowInterrupt $interrupt) {
            // Test JSON serialization
            $json = \json_encode($interrupt);
            $this->assertIsString($json);

            $decoded = \json_decode($json, true);
            $this->assertEquals('Workflow interrupted for human input', $decoded['message']);
            $this->assertEquals(['message' => 'Need human input'], $decoded['data']);
            $this->assertInstanceOf(InterruptableNode::class, \unserialize($decoded['currentNode']));

            // Test PHP serialization
            $serialized = \serialize($interrupt);
            $unserialized = \unserialize($serialized);

            $this->assertInstanceOf(WorkflowInterrupt::class, $unserialized);
            $this->assertEquals($interrupt->getData(), $unserialized->getData());
            $this->assertEquals($interrupt->getCurrentNode(), $unserialized->getCurrentNode());
        }
    }

    public function testMultipleInterruptsAndResumesWithCustomState(): void
    {
        $workflow = Workflow::make(
            state: new CustomState(),
            persistence: new InMemoryPersistence(),
            workflowId: 'multi-interrupt-workflow'
        )->addNodes([
            new NodeOne(),
            new MultipleInterruptionsNode(),
            new NodeThree(),
        ]);

        // First interrupt
        try {
            $workflow->start()->getResult();
            $this->fail('First interrupt not thrown');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['count' => 1, 'message' => 'Interrupt #1'], $interrupt->getData());
            $this->assertInstanceOf(CustomState::class, $interrupt->getState());
        }

        // Second interrupt
        try {
            $finalState = $workflow->start(true, 'second interrupt')->getResult();
            $this->fail('Second interrupt not thrown');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['count' => 2, 'message' => 'Interrupt #2'], $interrupt->getData());
            $this->assertInstanceOf(CustomState::class, $interrupt->getState());
        }

        // Final completion
        $finalState = $workflow->start(true, 'second response')->getResult();
        $this->assertTrue($finalState->get('all_interrupts_complete'));
        $this->assertEquals(3, $finalState->get('interrupt_count'));
        $this->assertInstanceOf(CustomState::class, $finalState);
    }
}
