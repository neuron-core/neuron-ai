<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\NodeCheckpoint;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\DefaultNodeRunner;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
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

        $node->setWorkflowContext($state, $event);

        $node->run($event, $state);

        $this->assertTrue($state->get('node_one_executed'));
        $this->assertEquals('data', $state->get('existing'));
    }

    public function testNodeCheckpoint(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = new WorkflowExecutor($persistence);

        $workflow = Workflow::make()->addNode(new NodeCheckpoint());

        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertEquals('test', $interrupt->getState()->get('checkpoint'));
            $state = $this->execute($workflow, $executor, $interrupt->getRequest());

            $this->assertEquals('test', $state->get('checkpoint'));
            $this->assertEquals($interrupt->getRequest()->getMessage(), $state->get('feedback'));
        }
    }
}
