<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;

class NodeTest extends TestCase
{
    public function testNodeImplementsInterface(): void
    {
        $node = new NodeOne();

        $this->assertInstanceOf(NodeInterface::class, $node);
    }

    public function testNodeRunMethodSignature(): void
    {
        $node = new NodeOne();
        $event = new FirstEvent('message');
        $state = new WorkflowState();

        $result = $node->run($event, $state);

        $this->assertInstanceOf(FirstEvent::class, $result);
        $this->assertEquals('First complete', $result->message);
    }

    public function testNodeStateModification(): void
    {
        $node = new NodeOne();
        $state = new WorkflowState(['existing' => 'data']);
        $event = new FirstEvent('test start');

        $node->setWorkflowContext($state, $event);

        $result = $node->run($event, $state);

        // Verify the state was modified
        $this->assertTrue($state->get('node_one_executed'));
        $this->assertEquals('test start', $state->get('start_message'));
        $this->assertEquals('data', $state->get('existing')); // Original data preserved

        $this->assertEquals('First complete', $result->message);
    }
}
