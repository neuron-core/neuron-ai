<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\MultipleInterruptionsNode;
use NeuronAI\Tests\Workflow\Stubs\SecondEvent;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\StartEvent;
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

        $workflow = Workflow::make(new InMemoryPersistence(), 'test-workflow')
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new InterruptableNode(),
                SecondEvent::class => new NodeThree(),
            ]);

        $workflow->run();
    }

    public function testWorkflowInterruptContainsData(): void
    {
        $workflow = Workflow::make(new InMemoryPersistence(), 'test-workflow')
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new InterruptableNode(),
                SecondEvent::class => new NodeThree(),
            ]);

        try {
            $workflow->run();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['message' => 'Need human input'], $interrupt->getData());
            $this->assertInstanceOf(InterruptableNode::class, $interrupt->getCurrentNode());
            $this->assertInstanceOf(WorkflowState::class, $interrupt->getState());
        }
    }

    public function testWorkflowInterruptPreservesState(): void
    {
        $initialState = new WorkflowState(['initial_data' => 'preserved']);

        $workflow = Workflow::make(new InMemoryPersistence(), 'test-workflow')
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new InterruptableNode(),
                SecondEvent::class => new NodeThree(),
            ]);

        try {
            $workflow->run($initialState);
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
        $workflow = Workflow::make(new InMemoryPersistence(), 'test-workflow')
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new InterruptableNode(),
                SecondEvent::class => new NodeThree(),
            ]);

        try {
            $workflow->run();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (WorkflowInterrupt $interrupt) {
            $currentEvent = $interrupt->getCurrentEvent();

            $this->assertNotNull($currentEvent);
            $this->assertInstanceOf(\NeuronAI\Tests\Workflow\Stubs\FirstEvent::class, $currentEvent);
            $this->assertEquals('First complete', $currentEvent->message);
        }
    }

    public function testWorkflowResume(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make($persistence, 'test-workflow')
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new InterruptableNode(),
                SecondEvent::class => new NodeThree(),
            ]);

        // First run - should interrupt
        try {
            $workflow->run();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (WorkflowInterrupt $interrupt) {
            // Verify interrupt occurred at correct node
            $this->assertInstanceOf(InterruptableNode::class, $interrupt->getCurrentNode());
        }

        // Resume with human feedback
        $finalState = $workflow->resume('human feedback provided');

        // Verify workflow completed successfully
        $this->assertTrue($finalState->get('node_one_executed'));
        $this->assertTrue($finalState->get('interruptable_node_executed'));
        $this->assertTrue($finalState->get('node_three_executed'));

        // Verify human feedback was processed
        $this->assertEquals('human feedback provided', $finalState->get('received_feedback'));
    }

    public function testWorkflowResumeWithComplexFeedback(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make($persistence, 'test-workflow')
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new InterruptableNode(),
                SecondEvent::class => new NodeThree(),
            ]);

        try {
            $workflow->run();
        } catch (WorkflowInterrupt) {
            // Expected
        }

        // Resume with array feedback
        $complexFeedback = ['decision' => 'approve', 'priority' => 'high'];
        $finalState = $workflow->resume($complexFeedback);

        $this->assertEquals($complexFeedback, $finalState->get('received_feedback'));
    }

    public function testWorkflowResumeWithoutPriorInterrupt(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make($persistence, 'empty-workflow');

        $this->expectException(\Exception::class); // Should fail when trying to load non-existent interrupt

        $workflow->resume('feedback');
    }

    public function testMultipleInterruptsAndResumes(): void
    {
        $workflow = Workflow::make(new InMemoryPersistence(), 'multi-interrupt-workflow')
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new MultipleInterruptionsNode(),
                SecondEvent::class => new NodeThree(),
            ]);

        // First interrupt
        try {
            $workflow->run();
            $this->fail('First interrupt not thrown');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['count' => 1, 'message' => 'Interrupt #1'], $interrupt->getData());
        }

        // Second interrupt
        try {
            $finalState = $workflow->resume(0);
            $this->fail('Second interrupt not thrown');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['count' => 2, 'message' => 'Interrupt #2'], $interrupt->getData());
        }

        // Third interrupt
        try {
            $finalState = $workflow->resume(0);
            $this->fail('Third interrupt not thrown');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['count' => 3, 'message' => 'Interrupt #3'], $interrupt->getData());
        }

        // Final completion
        $finalState = $workflow->resume('second response');
        $this->assertTrue($finalState->get('all_interrupts_complete'));
        $this->assertEquals(4, $finalState->get('interrupt_count'));
    }

    public function testWorkflowInterruptSerialization(): void
    {
        $workflow = Workflow::make(new InMemoryPersistence(), 'serialization-test')
            ->addNodes([
                StartEvent::class => new NodeOne(),
                FirstEvent::class => new InterruptableNode(),
            ]);

        try {
            $workflow->run();
        } catch (WorkflowInterrupt $interrupt) {
            // Test JSON serialization
            $json = \json_encode($interrupt);
            $this->assertIsString($json);

            $decoded = \json_decode($json, true);
            $this->assertEquals('Workflow interrupted for human input', $decoded['message']);
            $this->assertEquals(['message' => 'Need human input'], $decoded['data']);
            $this->assertInstanceOf(InterruptableNode::class, unserialize($decoded['currentNode']));

            // Test PHP serialization
            $serialized = \serialize($interrupt);
            $unserialized = \unserialize($serialized);

            $this->assertInstanceOf(WorkflowInterrupt::class, $unserialized);
            $this->assertEquals($interrupt->getData(), $unserialized->getData());
            $this->assertEquals($interrupt->getCurrentNode(), $unserialized->getCurrentNode());
        }
    }
}
