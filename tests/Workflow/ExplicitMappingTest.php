<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\StopEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class ExplicitMappingTest extends TestCase
{
    public function testExplicitEventNodeMapping(): void
    {
        // Create test events
        $firstEvent = new class () implements Event {
            public function __construct(public string $param = '')
            {
            }
        };
        new class () implements Event {
            public function __construct(public string $param = '')
            {
            }
        };

        // Create test nodes
        $nodeOne = new class () extends Node {
            public function run(Event $event, WorkflowState $state): Event
            {
                return new class ('First complete') implements Event {
                    public function __construct(public string $param)
                    {
                    }
                };
            }
        };

        $nodeTwo = new class () extends Node {
            public function run(Event $event, WorkflowState $state): Event
            {
                return new StopEvent();
            }
        };

        $workflow = Workflow::make()
            ->addNodes([
                StartEvent::class => $nodeOne,
                $firstEvent::class => $nodeTwo,
            ]);

        $eventNodeMap = $workflow->getEventNodeMap();

        // Verify correct event-to-node mappings
        $this->assertArrayHasKey(StartEvent::class, $eventNodeMap);
        $this->assertArrayHasKey($firstEvent::class, $eventNodeMap);

        // Verify correct node instances
        $this->assertSame($nodeOne, $eventNodeMap[StartEvent::class]);
        $this->assertSame($nodeTwo, $eventNodeMap[$firstEvent::class]);
    }

    public function testExplicitMappingWithStringNodes(): void
    {
        $testNode = new class () extends Node {
            public function run(Event $event, WorkflowState $state): Event
            {
                return new StopEvent();
            }
        };

        $workflow = Workflow::make()
            ->addNodes([
                StartEvent::class => $testNode::class,
            ]);

        $eventNodeMap = $workflow->getEventNodeMap();
        $this->assertArrayHasKey(StartEvent::class, $eventNodeMap);
        $this->assertInstanceOf($testNode::class, $eventNodeMap[StartEvent::class]);
    }

    public function testInvalidEventClassThrowsException(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('must implement Event interface');

        $testNode = new class () extends Node {
            public function run(Event $event, WorkflowState $state): Event
            {
                return new StopEvent();
            }
        };

        Workflow::make()->addNodes([
            'InvalidClass' => $testNode,
        ]);
    }

    public function testInvalidNodeThrowsException(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('must implement NodeInterface');

        Workflow::make()->addNodes([
            StartEvent::class => new \stdClass(),
        ]);
    }

    public function testEventNodeMapReset(): void
    {
        $nodeOne = new class () extends Node {
            public function run(Event $event, WorkflowState $state): Event
            {
                return new StopEvent();
            }
        };

        $nodeTwo = new class () extends Node {
            public function run(Event $event, WorkflowState $state): Event
            {
                return new StopEvent();
            }
        };

        $workflow = Workflow::make()
            ->addNodes([
                StartEvent::class => $nodeOne,
            ]);

        $eventNodeMap1 = $workflow->getEventNodeMap();
        $this->assertCount(1, $eventNodeMap1);

        // Adding new nodes should replace the entire mapping
        $workflow->addNodes([
            StartEvent::class => $nodeTwo,
        ]);

        $eventNodeMap2 = $workflow->getEventNodeMap();
        $this->assertCount(1, $eventNodeMap2);
        $this->assertSame($nodeTwo, $eventNodeMap2[StartEvent::class]);
    }
}
