<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use NeuronAI\Tests\Workflow\Stubs\ConditionalNode;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeForSecond;
use NeuronAI\Tests\Workflow\Stubs\NodeForThird;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;

class WorkflowTest extends TestCase
{
    public function testBasicLinearWorkflowExecution(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                 new NodeThree(),
            ]);

        $finalState = $workflow->start()->getResult();

        $this->assertTrue($finalState->get('node_one_executed'));
        $this->assertTrue($finalState->get('node_two_executed'));
        $this->assertTrue($finalState->get('node_three_executed'));
        $this->assertEquals('First complete', $finalState->get('first_message'));
        $this->assertEquals('Second complete', $finalState->get('second_message'));
    }

    public function testWorkflowWithInitialState(): void
    {
        $workflow = Workflow::make(new WorkflowState(['initial_data' => 'test']))
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                 new NodeThree(),
            ]);

        $finalState = $workflow->start()->getResult();

        $this->assertEquals('test', $finalState->get('initial_data'));
        $this->assertTrue($finalState->get('node_one_executed'));
    }

    public function testNodeClassStringInstantiation(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                 new NodeThree(),
            ]);

        $finalState = $workflow->start()->getResult();

        $this->assertTrue($finalState->get('node_one_executed'));
        $this->assertTrue($finalState->get('node_two_executed'));
        $this->assertTrue($finalState->get('node_three_executed'));
    }

    public function testEventNodeMapBuilding(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $workflow->start()->getResult();
        $eventNodeMap = $workflow->getEventNodeMap();

        $this->assertArrayHasKey(StartEvent::class, $eventNodeMap);
        $this->assertArrayHasKey(FirstEvent::class, $eventNodeMap);
    }

    public function testConditionalNodeWithUnionReturnType(): void
    {
        $nodes = [
            new NodeOne(),
            new ConditionalNode(),
            new NodeForSecond(),
            new NodeForThird(),
        ];

        $workflow = Workflow::make(new WorkflowState(['condition' => 'second']))
            ->addNodes($nodes);

        $finalState = $workflow->start()->getResult();

        $this->assertTrue($finalState->get('conditional_node_executed'));
        $this->assertTrue($finalState->get('second_path_executed'));
        $this->assertFalse($finalState->has('third_path_executed'));
        $this->assertEquals('Conditional chose second', $finalState->get('final_second_message'));

        // Test the third path
        $workflow = Workflow::make(new WorkflowState(['condition' => 'third']))
            ->addNodes($nodes);
        $finalState = $workflow->start()->getResult();

        $this->assertTrue($finalState->get('conditional_node_executed'));
        $this->assertTrue($finalState->get('third_path_executed'));
        $this->assertFalse($finalState->has('second_path_executed'));
        $this->assertEquals('Conditional chose third', $finalState->get('final_third_message'));
    }

    public function testWorkflowValidationFailsWithNoStartNode(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle '.StartEvent::class);

        $workflow = Workflow::make()
            ->addNodes([
                new NodeTwo(),
                 new NodeThree(),
            ]);

        $workflow->start()->getResult();
    }

    public function testWorkflowFailsWhenNoNodeHandlesEvent(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No node found that handle event');

        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                // Missing NodeTwo that handles FirstEvent
                new NodeThree(),
            ]);

        $workflow->start()->getResult();
    }

    public function testWorkflowInterrupt(): void
    {
        $this->expectException(WorkflowInterrupt::class);

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

        try {
            $workflow->start()->getResult();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals(['message' => 'Need human input'], $interrupt->getData());
            $this->assertInstanceOf(InterruptableNode::class, $interrupt->getCurrentNode());
        }

        // Resume with human feedback
        $finalState = $workflow->start(true)->getResult();

        $this->assertTrue($finalState->get('interruptable_node_executed'));
        $this->assertEquals('human input received', $finalState->get('received_feedback'));
    }
}
