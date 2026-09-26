<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\ProvidedResources;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

use function serialize;

class WorkflowResourcesTest extends TestCase
{
    public function test_values_are_stored_by_key_with_a_default_for_missing_ones(): void
    {
        $resources = new WorkflowResources(['client' => 'initial', 'zero' => 0]);
        $resources->set('client', 'replaced');

        $this->assertSame('replaced', $resources->get('client'));
        $this->assertSame(0, $resources->get('zero', 'fallback'));
        $this->assertSame('fallback', $resources->get('missing', 'fallback'));
        $this->assertNull($resources->get('missing'));
    }

    public function test_has_distinguishes_a_stored_null_from_a_missing_key(): void
    {
        $resources = new WorkflowResources(['nothing' => null]);

        $this->assertTrue($resources->has('nothing'));
        $this->assertFalse($resources->has('missing'));
    }

    public function test_a_node_receives_the_resources_of_its_segment(): void
    {
        $provided = new ProvidedResources(['client' => 'segment-client']);
        $workflow = Workflow::make('resources')
            ->setResources(fn (): WorkflowResources => $provided)
            ->addNode(new class () extends Node {
                public function __invoke(StartEvent $event, WorkflowState $state, ProvidedResources $resources): StopEvent
                {
                    $state->set('client', $resources->get('client'));
                    return new StopEvent();
                }
            });

        $this->assertSame('segment-client', $workflow->run()->get('client'));
    }

    public function test_middleware_and_nodes_share_one_resources_instance_per_segment(): void
    {
        $seen = [];
        $middleware = FakeMiddleware::make()
            ->setBeforeHandler(function (NodeInterface $node, Event $event, WorkflowState $state, WorkflowResources $resources) use (&$seen): void {
                $seen[] = $resources;
                $resources->set('trace', 'added by middleware');
            })
            ->setAfterHandler(function (NodeInterface $node, Event $event, WorkflowState $state, WorkflowResources $resources) use (&$seen): void {
                $seen[] = $resources;
            });
        $workflow = Workflow::make('resources')
            ->addGlobalMiddleware($middleware)
            ->addNode(new class () extends Node {
                public function __invoke(StartEvent $event, WorkflowState $state, WorkflowResources $resources): StopEvent
                {
                    $state->set('trace', $resources->get('trace'));
                    return new StopEvent();
                }
            });

        $state = $workflow->run();

        $this->assertSame('added by middleware', $state->get('trace'));
        $this->assertCount(2, $seen);
        $this->assertSame($seen[0], $seen[1]);
    }

    public function test_the_factory_wins_over_the_hook_and_runs_once_per_segment(): void
    {
        $workflow = new class () extends Workflow {
            public int $hookCalls = 0;

            protected function resources(): WorkflowResources
            {
                $this->hookCalls++;
                return new WorkflowResources(['origin' => 'hook']);
            }
        };
        $builds = 0;
        $workflow->setResources(function () use (&$builds): WorkflowResources {
            $builds++;
            return new WorkflowResources(['origin' => 'factory']);
        })->addNodes([
            new class () extends Node {
                public function __invoke(StartEvent $event, WorkflowState $state, WorkflowResources $resources): FirstEvent
                {
                    $state->set('first', $resources->get('origin'));
                    $resources->set('marker', 'set by the first node');
                    return new FirstEvent();
                }
            },
            new class () extends Node {
                public function __invoke(FirstEvent $event, WorkflowState $state, WorkflowResources $resources): StopEvent
                {
                    $state->set('marker', $resources->get('marker'));
                    return new StopEvent();
                }
            },
        ]);

        $first = $workflow->run();
        $second = $workflow->run();

        $this->assertSame('factory', $first->get('first'));
        $this->assertSame('set by the first node', $first->get('marker'));
        $this->assertSame('set by the first node', $second->get('marker'));
        $this->assertSame(2, $builds);
        $this->assertSame(0, $workflow->hookCalls);
    }

    public function test_the_hook_builds_fresh_resources_for_every_segment(): void
    {
        $workflow = new class () extends Workflow {
            /** @var WorkflowResources[] */
            public array $built = [];

            protected function resources(): WorkflowResources
            {
                return $this->built[] = new WorkflowResources();
            }
        };
        $workflow->addNode(new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state, WorkflowResources $resources): StopEvent
            {
                $state->set('visits', $resources->get('visits', 0) + 1);
                $resources->set('visits', $state->get('visits'));
                return new StopEvent();
            }
        });

        $first = $workflow->run();
        $second = $workflow->run();

        $this->assertSame(1, $first->get('visits'));
        $this->assertSame(1, $second->get('visits'));
        $this->assertCount(2, $workflow->built);
        $this->assertNotSame($workflow->built[0], $workflow->built[1]);
    }

    public function test_a_node_needing_resources_the_workflow_does_not_provide_fails_the_run_before_any_node_executes(): void
    {
        $persistence = new InMemoryPersistence();
        $node = new class () extends Node {
            public bool $executed = false;

            public function __invoke(StartEvent $event, WorkflowState $state, ProvidedResources $resources): StopEvent
            {
                $this->executed = true;
                return new StopEvent();
            }
        };
        $workflow = Workflow::make('missing-resources')->setPersistence($persistence)
            ->addNode(fn (): NodeInterface => $node);

        try {
            $workflow->run();
            $this->fail('The graph must refuse a node whose resources are not provided.');
        } catch (WorkflowException $e) {
            $this->assertStringEndsWith(
                '__invoke method needs ' . ProvidedResources::class . ', but the workflow provides ' . WorkflowResources::class,
                $e->getMessage(),
            );
        }

        $this->assertFalse($node->executed);
        $this->assertSame(WorkflowStatus::Failed, $workflow->inspect()?->status);
    }

    public function test_resources_are_never_persisted_with_the_run(): void
    {
        $persistence = new InMemoryPersistence();
        $secret = 'resource-api-key-' . __LINE__;
        $workflow = Workflow::make('unpersisted-resources')
            ->setPersistence($persistence)
            ->setResources(fn (): WorkflowResources => new WorkflowResources(['apiKey' => $secret]))
            ->addNode(new class () extends Node {
                public function __invoke(StartEvent $event, WorkflowState $state, WorkflowResources $resources): StopEvent
                {
                    $this->awaitEvent('continue');
                    return new StopEvent();
                }
            });

        $this->assertTrue($workflow->run()->isInterrupted());

        $this->assertStringNotContainsString($secret, serialize($persistence));
    }
}
