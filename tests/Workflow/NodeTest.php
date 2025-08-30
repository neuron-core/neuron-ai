<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\StartEvent;
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
}
