<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\WorkflowContext;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;

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
        $event = new StartEvent();
        $state = new WorkflowState();
        
        $result = $node->run($event, $state);
        
        $this->assertInstanceOf(FirstEvent::class, $result);
        $this->assertEquals('First complete', $result->message);
    }

    public function testNodeContextSetting(): void
    {
        $node = new NodeOne();
        $context = new WorkflowContext(
            'test-workflow',
            NodeOne::class,
            new InMemoryPersistence(),
            new WorkflowState()
        );
        
        $node->setContext($context);
        
        // Context is protected, so we can't directly test it
        // but we can verify it works by running the node
        $event = new StartEvent();
        $state = new WorkflowState();
        
        $result = $node->run($event, $state);
        $this->assertInstanceOf(FirstEvent::class, $result);
    }

    public function testNodeInterruptWithoutContext(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('WorkflowContext not set on node');
        
        $node = new class extends \NeuronAI\Workflow\Node {
            public function run(\NeuronAI\Workflow\Event $event, WorkflowState $state): \NeuronAI\Workflow\Event
            {
                $this->interrupt(['test' => 'data']);
                return $event;
            }
        };
        
        $node->run(new StartEvent(), new WorkflowState());
    }

    public function testNodeInterruptWithContext(): void
    {
        $this->expectException(WorkflowInterrupt::class);
        
        $node = new InterruptableNode();
        $context = new WorkflowContext(
            'test-workflow',
            InterruptableNode::class,
            new InMemoryPersistence(),
            new WorkflowState()
        );
        
        $node->setContext($context);
        $node->run(new FirstEvent(), new WorkflowState());
    }

    public function testNodeStateModification(): void
    {
        $node = new NodeOne();
        $state = new WorkflowState(['existing' => 'data']);
        $event = new StartEvent(['message' => 'test start']);
        
        $context = new WorkflowContext(
            'test-workflow',
            NodeOne::class,
            new InMemoryPersistence(),
            $state
        );
        $node->setContext($context);
        
        $result = $node->run($event, $state);
        
        // Verify state was modified
        $this->assertTrue($state->get('node_one_executed'));
        $this->assertEquals('test start', $state->get('start_message'));
        $this->assertEquals('data', $state->get('existing')); // Original data preserved
        
        // Verify return event
        $this->assertInstanceOf(FirstEvent::class, $result);
        $this->assertEquals('First complete', $result->message);
    }

    public function testNodePreservesExistingStateData(): void
    {
        $node = new NodeOne();
        $state = new WorkflowState([
            'existing_key' => 'existing_value',
            'another_key' => 123
        ]);
        $event = new StartEvent();
        
        $context = new WorkflowContext(
            'test-workflow',
            NodeOne::class,
            new InMemoryPersistence(),
            $state
        );
        $node->setContext($context);
        
        $node->run($event, $state);
        
        // Verify existing data is preserved
        $this->assertEquals('existing_value', $state->get('existing_key'));
        $this->assertEquals(123, $state->get('another_key'));
        
        // Verify new data was added
        $this->assertTrue($state->get('node_one_executed'));
    }
}