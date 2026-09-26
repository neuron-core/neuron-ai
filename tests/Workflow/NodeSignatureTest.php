<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Stub\AuditedEvent;
use NeuronAI\Tests\Workflow\Stub\CustomState;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\PrioritizedSignal;
use NeuronAI\Tests\Workflow\Stub\PrioritizedTestEvent;
use NeuronAI\Tests\Workflow\Stub\ProvidedResources;
use NeuronAI\Tests\Workflow\Stub\SecondEvent;
use Generator;
use NeuronAI\Exceptions\WorkflowException;
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
use Countable;
use Stringable;
use stdClass;

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

        $this->assertSame(StartEvent::class, $this->signature->eventClass($node, new WorkflowResources()));
    }

    public function test_resolves_intersection_to_its_event_member(): void
    {
        $node = new class () extends Node {
            public function __invoke(PrioritizedTestEvent&PrioritizedSignal $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        };

        $this->assertSame(PrioritizedTestEvent::class, $this->signature->eventClass($node, new WorkflowResources()));
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
        $this->signature->eventClass($node, new WorkflowResources());
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
        $this->signature->eventClass($node, new WorkflowResources());
    }

    public function test_rejects_missing_invoke(): void
    {
        $node = new class () extends Node {
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Missing __invoke method');
        $this->signature->eventClass($node, new WorkflowResources());
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
        $this->expectExceptionMessage('__invoke method must have 2 or 3 parameters');
        $this->signature->eventClass($node, new WorkflowResources());
    }

    public function test_accepts_the_resources_the_workflow_provides(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state, WorkflowResources $resources): StopEvent
            {
                return new StopEvent();
            }
        };

        $this->assertSame(StartEvent::class, $this->signature->eventClass($node, new ProvidedResources()));
    }

    public function test_rejects_resources_the_workflow_does_not_provide(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state, ProvidedResources $resources): StopEvent
            {
                return new StopEvent();
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('__invoke method needs ' . ProvidedResources::class . ', but the workflow provides ' . WorkflowResources::class);
        $this->signature->eventClass($node, new WorkflowResources());
    }

    public function test_rejects_a_third_parameter_that_is_not_resources(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state, string $extra): StopEvent
            {
                return new StopEvent();
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Third parameter of __invoke method must be ' . WorkflowResources::class);
        $this->signature->eventClass($node, new WorkflowResources());
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
        $this->signature->eventClass($node, new WorkflowResources());
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
        $this->signature->eventClass($node, new WorkflowResources());
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
        $this->signature->eventClass($node, new WorkflowResources());
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

        $this->assertSame(StartEvent::class, $this->signature->eventClass($node, new WorkflowResources()));
    }

    public function test_failure_names_the_node_class_and_the_reason(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event): StopEvent
            {
                return new StopEvent();
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Failed to validate ' . $node::class . ': __invoke method must have 2 or 3 parameters');
        $this->signature->eventClass($node, new WorkflowResources());
    }

    /** @return iterable<string, array{Node, string}> */
    public static function invalidSignatures(): iterable
    {
        yield 'no parameters' => [new class () extends Node {
            public function __invoke(): StopEvent
            {
                return new StopEvent();
            }
        }, '__invoke method must have 2 or 3 parameters'];
        yield 'four parameters' => [new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state, WorkflowResources $resources, string $extra): StopEvent
            {
                return new StopEvent();
            }
        }, '__invoke method must have 2 or 3 parameters'];
        yield 'untyped event' => [new class () extends Node {
            public function __invoke($event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        }, 'First parameter of __invoke method must have a type declaration'];
        yield 'class that is not an event' => [new class () extends Node {
            public function __invoke(stdClass $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        }, 'First parameter of __invoke method must be a type that implements ' . Event::class];
        yield 'intersection of two events' => [new class () extends Node {
            public function __invoke(PrioritizedTestEvent&AuditedEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        }, 'Intersection type must contain exactly one type that implements ' . Event::class];
        yield 'untyped state' => [new class () extends Node {
            public function __invoke(StartEvent $event, $state): StopEvent
            {
                return new StopEvent();
            }
        }, 'Second parameter of __invoke method must be ' . WorkflowState::class];
        yield 'union state' => [new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState|stdClass $state): StopEvent
            {
                return new StopEvent();
            }
        }, 'Second parameter of __invoke method must be ' . WorkflowState::class];
        yield 'untyped resources' => [new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state, $resources): StopEvent
            {
                return new StopEvent();
            }
        }, 'Third parameter of __invoke method must be ' . WorkflowResources::class];
        yield 'union resources' => [new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state, WorkflowResources|stdClass $resources): StopEvent
            {
                return new StopEvent();
            }
        }, 'Third parameter of __invoke method must be ' . WorkflowResources::class];
        yield 'no return type' => [new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state)
            {
                return new StopEvent();
            }
        }, '__invoke method must return a type that implements ' . Event::class];
        yield 'mixed return type' => [new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): mixed
            {
                return new StopEvent();
            }
        }, '__invoke method must return a type that implements ' . Event::class];
        yield 'union return with a non-event member' => [new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent|string
            {
                return $state->has('text') ? 'text' : new StopEvent();
            }
        }, 'All return types in union must implement ' . Event::class];
    }

    #[DataProvider('invalidSignatures')]
    public function test_rejects_invalid_signature(Node $node, string $reason): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Failed to validate ' . $node::class . ': ' . $reason);
        $this->signature->eventClass($node, new WorkflowResources());
    }

    public function test_accepts_a_state_subclass_and_resources_subclass_the_workflow_provides(): void
    {
        $node = new class () extends Node {
            public function __invoke(FirstEvent $event, CustomState $state, ProvidedResources $resources): SecondEvent
            {
                return new SecondEvent();
            }
        };

        $this->assertSame(FirstEvent::class, $this->signature->eventClass($node, new ProvidedResources()));
    }

    public function test_a_nullable_event_parameter_routes_its_event_class(): void
    {
        $node = new class () extends Node {
            public function __invoke(?FirstEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        };

        $this->assertSame(FirstEvent::class, $this->signature->eventClass($node, new WorkflowResources()));
    }

    public function test_resources_are_checked_against_the_instance_the_workflow_provides(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state, ProvidedResources $resources): StopEvent
            {
                return new StopEvent();
            }
        };
        $subclass = new class () extends ProvidedResources {
        };

        $this->assertSame(StartEvent::class, $this->signature->eventClass($node, $subclass));
    }
}
