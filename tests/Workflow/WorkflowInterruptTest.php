<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

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

        $workflow = Workflow::make(new InMemoryPersistence(), 'test-workflow')
            ->addNodes([
                new NodeOne(),
                new InterruptableNode(),
                new NodeThree(),
            ]);

        $workflow->run();
    }

    public function testWorkflowInterruptContainsData(): void
    {
        $workflow = Workflow::make(new InMemoryPersistence(), 'test-workflow')
            ->addNodes([
                new NodeOne(),
                new InterruptableNode(),
                new NodeThree(),
            ]);

        try {
            $workflow->run();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['message' => 'Need human input'], $interrupt->getData());
            $this->assertEquals(InterruptableNode::class, $interrupt->getCurrentNode());
            $this->assertInstanceOf(WorkflowState::class, $interrupt->getState());
        }
    }

    public function testWorkflowInterruptPreservesState(): void
    {
        $initialState = new WorkflowState(['initial_data' => 'preserved']);

        $workflow = Workflow::make(new InMemoryPersistence(), 'test-workflow')
            ->addNodes([
                new NodeOne(),
                new InterruptableNode(),
                new NodeThree(),
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
                new NodeOne(),
                new InterruptableNode(),
                new NodeThree(),
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
                new NodeOne(),
                new InterruptableNode(),
                new NodeThree(),
            ]);

        // First run - should interrupt
        try {
            $workflow->run();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (WorkflowInterrupt $interrupt) {
            // Verify interrupt occurred at correct node
            $this->assertEquals(InterruptableNode::class, $interrupt->getCurrentNode());
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
                new NodeOne(),
                new InterruptableNode(),
                new NodeThree(),
            ]);

        try {
            $workflow->run();
        } catch (WorkflowInterrupt $interrupt) {
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
        // Create a node that interrupts multiple times
        $multiInterruptNode = new class () extends \NeuronAI\Workflow\Node {
            public function run(\Tests\Workflow\Stubs\FirstEvent $event, WorkflowState $state): \Tests\Workflow\Stubs\SecondEvent
            {
                $interruptCount = $state->get('interrupt_count', 0);
                $interruptCount++;
                $state->set('interrupt_count', $interruptCount);

                if ($interruptCount < 3) {
                    $this->interrupt(['count' => $interruptCount, 'message' => "Interrupt #{$interruptCount}"]);
                }

                $state->set('all_interrupts_complete', true);
                return new \Tests\Workflow\Stubs\SecondEvent('All interrupts complete');
            }
        };

        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make($persistence, 'multi-interrupt-workflow')
            ->addNodes([
                new NodeOne(),
                $multiInterruptNode,
                new NodeThree(),
            ]);

        // First interrupt
        try {
            $workflow->run();
            $this->fail('Expected first interrupt');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['count' => 1, 'message' => 'Interrupt #1'], $interrupt->getData());
        }

        // Second interrupt
        try {
            $finalState = $workflow->resume('first response');
            $this->fail('Expected second interrupt');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['count' => 2, 'message' => 'Interrupt #2'], $interrupt->getData());
        }

        // Final completion
        $finalState = $workflow->resume('second response');
        $this->assertTrue($finalState->get('all_interrupts_complete'));
        $this->assertEquals(3, $finalState->get('interrupt_count'));
    }

    public function testWorkflowInterruptSerialization(): void
    {
        $workflow = Workflow::make(new InMemoryPersistence(), 'serialization-test')
            ->addNodes([
                new NodeOne(),
                new InterruptableNode(),
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
            $this->assertEquals(InterruptableNode::class, $decoded['currentNode']);

            // Test PHP serialization
            $serialized = \serialize($interrupt);
            $unserialized = \unserialize($serialized);

            $this->assertInstanceOf(WorkflowInterrupt::class, $unserialized);
            $this->assertEquals($interrupt->getData(), $unserialized->getData());
            $this->assertEquals($interrupt->getCurrentNode(), $unserialized->getCurrentNode());
        }
    }
}
