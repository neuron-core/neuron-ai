<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use Countable;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Stub\AuditedEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\NodeSignature;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

abstract class AbstractRoutedEvent implements Event
{
}

class ConcreteAuditedEvent implements AuditedEvent
{
}

class NodeSignatureAbstractEventTest extends TestCase
{
    /** @return iterable<string, array{Node}> */
    public static function unreachableEventTypes(): iterable
    {
        yield 'the Event interface itself' => [new class () extends Node {
            public function __invoke(Event $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        }];
        yield 'an interface extending Event' => [new class () extends Node {
            public function __invoke(AuditedEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        }];
        yield 'an abstract event class' => [new class () extends Node {
            public function __invoke(AbstractRoutedEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        }];
        yield 'an intersection whose event member is an interface' => [new class () extends Node {
            public function __invoke(AuditedEvent&Countable $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        }];
    }

    #[DataProvider('unreachableEventTypes')]
    public function test_rejects_event_types_that_exact_class_routing_can_never_reach(Node $node): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Failed to validate ' . $node::class . ': First parameter of __invoke method must be a concrete event class');

        (new NodeSignature())->eventClass($node, new WorkflowResources());
    }

    public function test_workflow_with_an_interface_typed_node_fails_before_running_any_node(): void
    {
        $workflow = Workflow::make()->addNodes([
            new class () extends Node {
                public function __invoke(StartEvent $event, WorkflowState $state): ConcreteAuditedEvent
                {
                    $state->set('start_node_ran', true);
                    return new ConcreteAuditedEvent();
                }
            },
            new class () extends Node {
                public function __invoke(AuditedEvent $event, WorkflowState $state): StopEvent
                {
                    return new StopEvent();
                }
            },
        ]);

        try {
            $workflow->run();
            $this->fail('A node typed with an interface must be rejected when the graph is built');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('must be a concrete event class', $e->getMessage());
        }
    }
}
