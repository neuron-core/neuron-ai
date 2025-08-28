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
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Tests\Workflow\Stubs\SecondEvent;

class TypeHintDiscoveryTest extends TestCase
{
    public function testEventNodeMapDiscovery(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
            ]);

        $eventNodeMap = $workflow->getEventNodeMap();

        // Verify correct event-to-node mappings
        $this->assertArrayHasKey(StartEvent::class, $eventNodeMap);
        $this->assertArrayHasKey(FirstEvent::class, $eventNodeMap);
        
        // Verify each event maps to exactly one node
        $this->assertCount(1, $eventNodeMap[StartEvent::class]);
        $this->assertCount(1, $eventNodeMap[FirstEvent::class]);
        
        // Verify correct node classes
        $this->assertArrayHasKey(NodeOne::class, $eventNodeMap[StartEvent::class]);
        $this->assertArrayHasKey(NodeTwo::class, $eventNodeMap[FirstEvent::class]);
    }

    public function testTypeHintValidation(): void
    {
        $invalidNode = new class extends Node {
            public function run(mixed $event, WorkflowState $state): Event // Invalid: mixed type
            {
                return new StartEvent();
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('first parameter must be an Event type');

        $workflow = Workflow::make()->addNode($invalidNode);
        $workflow->getEventNodeMap();
    }

    public function testBuiltinTypeRejection(): void
    {
        $invalidNode = new class extends Node {
            public function run(string $event, WorkflowState $state): Event // Invalid: builtin type
            {
                return new StartEvent();
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('first parameter must be an Event type');

        $workflow = Workflow::make()->addNode($invalidNode);
        $workflow->getEventNodeMap();
    }

    public function testInsufficientParameters(): void
    {
        $invalidNode = new class extends Node {
            public function run(Event $event): Event // Invalid: missing WorkflowState parameter
            {
                return $event;
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('must have at least 2 parameters');

        $workflow = Workflow::make()->addNode($invalidNode);
        $workflow->getEventNodeMap();
    }

    public function testEventNodeMapCaching(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
            ]);

        // First call builds the map
        $eventNodeMap1 = $workflow->getEventNodeMap();
        
        // Second call should return cached version
        $eventNodeMap2 = $workflow->getEventNodeMap();
        
        $this->assertSame($eventNodeMap1, $eventNodeMap2);
    }

    public function testEventNodeMapResetOnNodeAdd(): void
    {
        $workflow = Workflow::make()
            ->addNode(new NodeOne());

        $eventNodeMap1 = $workflow->getEventNodeMap();
        $this->assertCount(1, $eventNodeMap1);

        // Adding a node should reset the cache
        $workflow->addNode(new NodeTwo());
        $eventNodeMap2 = $workflow->getEventNodeMap();
        
        $this->assertCount(2, $eventNodeMap2);
        $this->assertNotSame($eventNodeMap1, $eventNodeMap2);
    }

    public function testComplexEventTypeHierarchy(): void
    {
        // Create a custom event that extends another event
        $customEvent = new class implements Event {};
        
        $customNode = new class extends Node {
            public function run(Event $event, WorkflowState $state): Event
            {
                return $event;
            }
        };

        $workflow = Workflow::make()->addNode($customNode);
        $eventNodeMap = $workflow->getEventNodeMap();

        // Should map to base Event interface
        $this->assertArrayHasKey(Event::class, $eventNodeMap);
    }

    public function testMultipleNodesForSameEventThrowsException(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Multiple nodes found that accept event');

        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeOne(), // Duplicate - both accept StartEvent
            ]);

        $workflow->run();
    }

    public function testReflectionBasedTypeDiscovery(): void
    {
        $nodeWithInterfaceType = new class extends Node {
            public function run(Event $event, WorkflowState $state): SecondEvent
            {
                return new SecondEvent();
            }
        };

        $workflow = Workflow::make()->addNode($nodeWithInterfaceType);
        $eventNodeMap = $workflow->getEventNodeMap();

        // Should correctly identify Event interface as the parameter type
        $this->assertArrayHasKey(Event::class, $eventNodeMap);
        $nodeClass = get_class($nodeWithInterfaceType);
        $this->assertArrayHasKey($nodeClass, $eventNodeMap[Event::class]);
    }
}