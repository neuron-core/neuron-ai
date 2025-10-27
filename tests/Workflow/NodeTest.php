<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Stubs\NodeCheckpoint;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;

class NodeTest extends TestCase
{
    public function testNodeRunMethodSignature(): void
    {
        $node = new NodeOne();
        $event = new StartEvent();
        $state = new WorkflowState();

        $result = $node->run($event, $state);

        $this->assertInstanceOf(FirstEvent::class, $result);
        $this->assertEquals('First complete', $result->message);
    }

    public function testNodeStateModification(): void
    {
        $node = new NodeOne();
        $state = new WorkflowState(['existing' => 'data']);
        $event = new StartEvent();

        $node->setWorkflowContext($state, $event);

        $node->run($event, $state);

        // Verify the state was modified
        $this->assertTrue($state->get('node_one_executed'));
        $this->assertEquals('data', $state->get('existing')); // Original data preserved
    }

    public function testNodeCheckpoint(): void
    {
        $workflow = Workflow::make()->addNode(new NodeCheckpoint());

        try {
            $state = $workflow->start()->getResult();
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals('test', $interrupt->getState()->get('checkpoint'));
            $state = $workflow->start($interrupt->getRequest())->getResult();

            $this->assertEquals('test', $state->get('checkpoint'));
            $this->assertEquals($interrupt->getRequest()->getReason(), $state->get('feedback'));
        }
    }
}
