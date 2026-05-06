<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stubs\CustomState;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class WorkflowValidationTest extends TestCase
{
    use ExecutorTestHelpers;

    public function testValidationFailsWithEmptyWorkflow(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle ' . StartEvent::class);

        $workflow = Workflow::make();
        $this->execute($workflow);
    }

    public function testValidationFailsWithMissingStartNode(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle ' . StartEvent::class);

        $workflow = Workflow::make()
            ->addNode(new NodeTwo())
            ->addNode(new NodeThree());

        $this->execute($workflow);
    }

    public function testValidationWithMissingHandler(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No node found that handle event: ' . FirstEvent::class);

        $invalidNode = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): FirstEvent
            {
                return new FirstEvent('');
            }
        };

        $workflow = Workflow::make()->addNode($invalidNode);
        $this->execute($workflow);
    }

    public function testValidationCustomState(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, CustomState $state): StopEvent
            {
                return new StopEvent();
            }
        };

        $workflow = Workflow::make(state: new CustomState())->addNode($node);
        $state = $this->execute($workflow);
        $this->assertInstanceOf(CustomState::class, $state);
        $this->assertEquals('custom property', $state->custom);
    }
}
