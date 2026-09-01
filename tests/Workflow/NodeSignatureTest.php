<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Stub\PrioritizedSignal;
use NeuronAI\Tests\Workflow\Stub\PrioritizedTestEvent;
use Generator;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\NodeSignature;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use Countable;
use Stringable;

class NodeSignatureTest extends TestCase
{
    protected NodeSignature $signature;

    protected function setUp(): void
    {
        $this->signature = new NodeSignature();
    }

    public function test_resolves_named_event_class(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        };

        $this->assertSame(StartEvent::class, $this->signature->eventClass($node));
    }

    public function test_resolves_intersection_to_its_event_member(): void
    {
        $node = new class () extends Node {
            public function __invoke(PrioritizedTestEvent&PrioritizedSignal $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        };

        $this->assertSame(PrioritizedTestEvent::class, $this->signature->eventClass($node));
    }

    public function test_intersection_node_routes_inside_a_workflow(): void
    {
        $workflow = Workflow::make()->addNodes([
            new class () extends Node {
                public function __invoke(StartEvent $event, WorkflowState $state): PrioritizedTestEvent
                {
                    return new PrioritizedTestEvent();
                }
            },
            new class () extends Node {
                public function __invoke(PrioritizedTestEvent&PrioritizedSignal $event, WorkflowState $state): StopEvent
                {
                    $state->set('routed_through_intersection', true);
                    return new StopEvent();
                }
            },
        ]);

        $state = $workflow->run();

        $this->assertTrue($state->get('routed_through_intersection'));
    }

    public function test_rejects_intersection_without_an_event_member(): void
    {
        $node = new class () extends Node {
            public function __invoke(Countable&Stringable $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Intersection type must contain exactly one type that implements ' . Event::class);
        $this->signature->eventClass($node);
    }

    public function test_rejects_union_event_type(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent|StopEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Nodes can handle only one event type.');
        $this->signature->eventClass($node);
    }

    public function test_rejects_missing_invoke(): void
    {
        $node = new class () extends Node {
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Missing __invoke method');
        $this->signature->eventClass($node);
    }

    public function test_rejects_wrong_parameter_count(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event): StopEvent
            {
                return new StopEvent();
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('__invoke method must have exactly 2 parameters');
        $this->signature->eventClass($node);
    }

    public function test_rejects_non_event_first_parameter(): void
    {
        $node = new class () extends Node {
            public function __invoke(string $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('First parameter of __invoke method must be a type that implements ' . Event::class);
        $this->signature->eventClass($node);
    }

    public function test_rejects_non_state_second_parameter(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, string $state): StopEvent
            {
                return new StopEvent();
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Second parameter of __invoke method must be ' . WorkflowState::class);
        $this->signature->eventClass($node);
    }

    public function test_rejects_invalid_return_type(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): string
            {
                return '';
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('__invoke method must return a type that implements ' . Event::class);
        $this->signature->eventClass($node);
    }

    public function test_accepts_generator_and_union_return_types(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): Generator|StopEvent
            {
                if ($state->has('stream')) {
                    return $this->chunks();
                }

                return new StopEvent();
            }

            protected function chunks(): Generator
            {
                yield from [];
            }
        };

        $this->assertSame(StartEvent::class, $this->signature->eventClass($node));
    }
}
