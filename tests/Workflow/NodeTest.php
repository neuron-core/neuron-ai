<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\NodeContext;
use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\NodeCheckpoint;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class NodeTest extends TestCase
{
    use ExecutorTestHelpers;

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

        $node->setWorkflowContext(new NodeContext($state, $event));

        $node->run($event, $state);

        $this->assertTrue($state->get('node_one_executed'));
        $this->assertEquals('data', $state->get('existing'));
    }

    public function testNodeCheckpoint(): void
    {
        $executor = new WorkflowExecutor(new LocalStepEngine(new InMemoryPersistence()));

        $workflow = Workflow::make()->addNode(new NodeCheckpoint());

        $state = $this->execute($workflow, $executor);

        // Paused: the checkpoint ran, the node blocked at interrupt() before writing feedback
        $this->assertTrue($state->isInterrupted());
        $this->assertSame('test', $state->get('checkpoint'));
        $this->assertNull($state->get('feedback'));

        // Resume delivers the payload; interrupt() returns it on resume.
        $state = $this->resume($workflow, $executor, ['message' => 'what do you mean?']);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('test', $state->get('checkpoint'));
        $this->assertSame('what do you mean?', $state->get('feedback'));
    }
}
