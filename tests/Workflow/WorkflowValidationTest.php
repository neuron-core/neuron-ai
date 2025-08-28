<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\SecondEvent;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;

class WorkflowValidationTest extends TestCase
{
    public function testValidationFailsWithEmptyWorkflow(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle '.StartEvent::class);

        $workflow = Workflow::make();
        $workflow->run();
    }

    public function testValidationFailsWithDuplicateStartNodeInstances(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle '.StartEvent::class);

        $node1 = new NodeOne();
        $node2 = new NodeOne();

        $workflow = Workflow::make()
            ->addNode(FirstEvent::class, $node1)
            ->addNode(SecondEvent::class, $node2);

        $workflow->run();
    }

    public function testValidationWithInvalidNodeMethodSignature(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No node found that handle event: '.FirstEvent::class);

        $invalidNode = new class () extends Node {
            public function run(Event $event, WorkflowState $state): Event // Missing WorkflowState parameter
            {
                return new FirstEvent('');
            }
        };

        $workflow = Workflow::make()->addNode(StartEvent::class, $invalidNode);
        $workflow->run();
    }

    public function testValidationWithCircularDependency(): void
    {
        // Create a custom event for a circular dependency test
        $customEvent = new class () implements Event {};

        $nodeA = new class ($customEvent) extends Node {
            public function __construct(private readonly Event $customEventClass)
            {
            }

            public function run(Event $event, WorkflowState $state): Event
            {
                return new ($this->customEventClass::class)();
            }
        };

        $nodeB = new class ($customEvent) extends Node {
            public function __construct()
            {
            }

            public function run(Event $event, WorkflowState $state): Event // Creates circular dependency
            {
                return new FirstEvent();
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Node for event '.StartEvent::class.' already exists');

        $workflow = Workflow::make()
            ->addNode(StartEvent::class, $nodeA)
            ->addNode(StartEvent::class, $nodeB);

        $workflow->run();
    }
}
