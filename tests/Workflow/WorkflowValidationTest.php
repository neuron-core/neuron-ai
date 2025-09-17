<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Stubs\CustomState;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\StopEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class WorkflowValidationTest extends TestCase
{
    public function testValidationFailsWithEmptyWorkflow(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle '.StartEvent::class);

        $workflow = Workflow::make();
        $workflow->start()->getResult();
    }

    public function testValidationFailsWithMissingStartNode(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle '.StartEvent::class);

        $node1 = new NodeTwo();
        $node2 = new NodeThree();

        $workflow = Workflow::make()
            ->addNode($node1)
            ->addNode($node2);

        $workflow->start()->getResult();
    }

    public function testValidationWithMissingHandler(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No node found that handle event: '.FirstEvent::class);

        $invalidNode = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): FirstEvent
            {
                return new FirstEvent('');
            }
        };

        $workflow = Workflow::make()->addNode($invalidNode);
        $workflow->start()->getResult();
    }

    public function testValidationCustomState(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, CustomState $state): StopEvent
            {
                return new StopEvent();
            }
        };

        $workflow = Workflow::make(new CustomState())->addNode($node);
        $this->assertInstanceOf(CustomState::class, $workflow->start()->getResult());
    }
}
