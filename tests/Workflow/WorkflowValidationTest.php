<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;

class WorkflowValidationTest extends TestCase
{
    public function testValidationFailsWithEmptyWorkflow(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that accept StartEvent');

        $workflow = Workflow::make();
        $workflow->run();
    }

    public function testValidationFailsWithNoStartNode(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that accept StartEvent');

        $workflow = Workflow::make()
            ->addNodes([
                new NodeTwo(), // Accepts FirstEvent, not StartEvent
                new NodeThree(), // Accepts SecondEvent
            ]);

        $workflow->run();
    }

    public function testValidationFailsWithMultipleStartNodes(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Multiple nodes found that accept StartEvent. Only one start node is allowed.');

        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(), // Accepts StartEvent
                new NodeOne(), // Another node that accepts StartEvent
            ]);

        $workflow->run();
    }

    public function testValidationFailsWithDuplicateStartNodeInstances(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Multiple nodes found that accept StartEvent');

        $node1 = new NodeOne();
        $node2 = new NodeOne();

        $workflow = Workflow::make()
            ->addNode($node1)
            ->addNode($node2);

        $workflow->run();
    }

    public function testValidationFailsWithMissingIntermediateNode(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No node found that accepts event');

        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(), // Produces FirstEvent
                // Missing NodeTwo that accepts FirstEvent
                new NodeThree(), // Accepts SecondEvent
            ]);

        $workflow->run();
    }

    public function testValidationWithInvalidNodeMethodSignature(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('must have at least 2 parameters');

        $invalidNode = new class () extends Node {
            public function run(StartEvent $event, \NeuronAI\Workflow\WorkflowState $state): Event // Missing WorkflowState parameter
            {
                return $event;
            }
        };

        $workflow = Workflow::make()->addNode($invalidNode);
        $workflow->run();
    }

    public function testValidationWithNonTypedParameter(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('first parameter must be an Event type');

        $invalidNode = new class () extends Node {
            public function run($event, WorkflowState $state): Event // No type hint
            {
                return new StartEvent();
            }
        };

        $workflow = Workflow::make()->addNode($invalidNode);
        $workflow->run();
    }

    public function testValidationWithBuiltinTypeParameter(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('first parameter must be an Event type');

        $invalidNode = new class () extends Node {
            public function run(string $event, WorkflowState $state): Event // Builtin type
            {
                return new StartEvent();
            }
        };

        $workflow = Workflow::make()->addNode($invalidNode);
        $workflow->run();
    }

    public function testValidationWithNonEventParameter(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('first parameter must be an Event type');

        $invalidNode = new class () extends Node {
            public function run(\stdClass $event, WorkflowState $state): Event // Not an Event
            {
                return new StartEvent();
            }
        };

        $workflow = Workflow::make()->addNode($invalidNode);
        $workflow->run();
    }

    public function testValidationErrorContainsNodeClassName(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessageMatches('/class@anonymous.*must have at least 2 parameters/');

        $invalidNode = new class () extends Node {
            public function run(StartEvent $event, \NeuronAI\Workflow\WorkflowState $state): Event
            {
                return $event;
            }
        };

        $workflow = Workflow::make()->addNode($invalidNode);
        $workflow->run();
    }

    public function testValidationWithCircularDependency(): void
    {
        // Create a custom event for circular dependency test
        $customEvent = new class () implements Event {};

        $nodeA = new class ($customEvent) extends Node {
            public function __construct(private readonly Event $customEventClass)
            {
            }

            public function run(StartEvent $event, WorkflowState $state): Event
            {
                return new ($this->customEventClass::class)();
            }
        };

        $nodeB = new class ($customEvent) extends Node {
            public function __construct()
            {
            }

            public function run(Event $event, WorkflowState $state): StartEvent // Creates circular dependency
            {
                return new StartEvent();
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Multiple nodes found that accept StartEvent');

        $workflow = Workflow::make()
            ->addNode($nodeA)
            ->addNode($nodeB);

        $workflow->run();
    }

    public function testValidationReportsCorrectEventType(): void
    {
        $workflow = Workflow::make()->addNode(new NodeOne());

        try {
            $workflow->run();
            $this->fail('Expected WorkflowException');
        } catch (WorkflowException) {
            // Should not fail during validation, but during execution when FirstEvent has no handler
        }

        // Add incomplete workflow and try to run
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(), // Produces FirstEvent
                // Missing NodeTwo - should fail at runtime
            ]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessageMatches('/No node found that accepts event.*FirstEvent/');

        $workflow->run();
    }
}
