<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Middleware;

use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Support\ExecutionTestFactory;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\InterruptableNode;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Tests\Workflow\Stub\SecondEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function array_merge;

class WorkflowMiddlewareTest extends TestCase
{
    use ExecutorTestHelpers;

    public function test_global_middleware_is_called_for_every_node(): void
    {
        $middleware = FakeMiddleware::make();

        $this->execute(
            Workflow::make('test-workflow')
                ->addGlobalMiddleware(fn (): FakeMiddleware => $middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $middleware->assertBeforeCalledTimes(3);
        $middleware->assertAfterCalledTimes(3);
        $middleware->assertCallCount(6);
    }

    public function test_node_specific_middleware_only_runs_for_target_node(): void
    {
        $middleware = FakeMiddleware::make();

        $this->execute(
            Workflow::make('test-workflow')
                ->addMiddleware(NodeOne::class, fn (): FakeMiddleware => $middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $middleware->assertBeforeCalledTimes(1);
        $middleware->assertAfterCalledTimes(1);
        $middleware->assertBeforeCalledForNode(NodeOne::class);
        $middleware->assertAfterCalledForNode(NodeOne::class);
    }

    public function test_multiple_global_middleware_execute_in_registration_order(): void
    {
        $order = [];

        $first = FakeMiddleware::make()
            ->setBeforeHandler(function () use (&$order): void {
                $order[] = 'first.before';
            })
            ->setAfterHandler(function () use (&$order): void {
                $order[] = 'first.after';
            });

        $second = FakeMiddleware::make()
            ->setBeforeHandler(function () use (&$order): void {
                $order[] = 'second.before';
            })
            ->setAfterHandler(function () use (&$order): void {
                $order[] = 'second.after';
            });

        $this->execute(
            Workflow::make('test-workflow')
                ->addGlobalMiddleware([$first, $second])
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $expectedPerNode = [
            'first.before', 'second.before', 'first.after', 'second.after',
        ];

        $expected = array_merge($expectedPerNode, $expectedPerNode, $expectedPerNode);

        $this->assertSame($expected, $order);
    }

    public function test_before_receives_correct_event_per_node(): void
    {
        $middleware = FakeMiddleware::make();

        $this->execute(
            Workflow::make('test-workflow')
                ->addGlobalMiddleware(fn (): FakeMiddleware => $middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $beforeRecords = $middleware->getBeforeRecords();

        $this->assertCount(3, $beforeRecords);
        $this->assertInstanceOf(StartEvent::class, $beforeRecords[0]->event);
        $this->assertInstanceOf(NodeOne::class, $beforeRecords[0]->node);
        $this->assertInstanceOf(FirstEvent::class, $beforeRecords[1]->event);
        $this->assertSame('First complete', $beforeRecords[1]->event->message);
        $this->assertInstanceOf(NodeTwo::class, $beforeRecords[1]->node);
        $this->assertInstanceOf(SecondEvent::class, $beforeRecords[2]->event);
        $this->assertSame('Second complete', $beforeRecords[2]->event->message);
        $this->assertInstanceOf(NodeThree::class, $beforeRecords[2]->node);
    }

    public function test_after_receives_node_return_event(): void
    {
        $middleware = FakeMiddleware::make();

        $this->execute(
            Workflow::make('test-workflow')
                ->addGlobalMiddleware(fn (): FakeMiddleware => $middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $afterRecords = $middleware->getAfterRecords();

        $this->assertCount(3, $afterRecords);
        $this->assertInstanceOf(FirstEvent::class, $afterRecords[0]->event);
        $this->assertSame('First complete', $afterRecords[0]->event->message);
        $this->assertInstanceOf(SecondEvent::class, $afterRecords[1]->event);
        $this->assertSame('Second complete', $afterRecords[1]->event->message);
        $this->assertInstanceOf(StopEvent::class, $afterRecords[2]->event);
        $this->assertSame('Workflow complete', $afterRecords[2]->event->getResult());
    }

    public function test_global_and_node_middleware_combine(): void
    {
        $global = FakeMiddleware::make();
        $nodeSpecific = FakeMiddleware::make();

        $this->execute(
            Workflow::make('test-workflow')
                ->addGlobalMiddleware(fn (): FakeMiddleware => $global)
                ->addMiddleware(NodeTwo::class, fn (): FakeMiddleware => $nodeSpecific)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $global->assertBeforeCalledTimes(3);
        $global->assertAfterCalledTimes(3);

        $nodeSpecific->assertBeforeCalledTimes(1);
        $nodeSpecific->assertAfterCalledTimes(1);
        $nodeSpecific->assertBeforeCalledForNode(NodeTwo::class);
    }

    public function test_global_middleware_runs_before_node_middleware(): void
    {
        $order = [];

        $global = FakeMiddleware::make()
            ->setBeforeHandler(function () use (&$order): void {
                $order[] = 'global.before';
            })
            ->setAfterHandler(function () use (&$order): void {
                $order[] = 'global.after';
            });

        $nodeSpecific = FakeMiddleware::make()
            ->setBeforeHandler(function () use (&$order): void {
                $order[] = 'node.before';
            })
            ->setAfterHandler(function () use (&$order): void {
                $order[] = 'node.after';
            });

        $this->execute(
            Workflow::make('test-workflow')
                ->addGlobalMiddleware(fn (): FakeMiddleware => $global)
                ->addMiddleware(NodeOne::class, fn (): FakeMiddleware => $nodeSpecific)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $this->assertSame([
            'global.before', 'node.before', 'global.after', 'node.after',
            'global.before', 'global.after',
            'global.before', 'global.after',
        ], $order);
    }

    public function test_middleware_can_read_and_write_state(): void
    {
        $middleware = FakeMiddleware::make()
            ->setBeforeHandler(function (NodeInterface $node, Event $event, WorkflowState $state): void {
                $state->set('injected_by_middleware', true);
            });

        $finalState = $this->execute(
            Workflow::make('test-workflow')
                ->addMiddleware(NodeOne::class, fn (): FakeMiddleware => $middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $this->assertTrue($finalState->get('injected_by_middleware'));
    }

    public function test_middleware_on_multiple_node_classes(): void
    {
        $middleware = FakeMiddleware::make();

        $this->execute(
            Workflow::make('test-workflow')
                ->addMiddleware([NodeOne::class, NodeThree::class], fn (): FakeMiddleware => $middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $middleware->assertBeforeCalledTimes(2);
        $middleware->assertAfterCalledTimes(2);
        $middleware->assertBeforeCalledForNode(NodeOne::class);
        $middleware->assertBeforeCalledForNode(NodeThree::class);
    }

    public function test_node_middleware_is_only_called_for_its_node(): void
    {
        $middlewareForTwo = FakeMiddleware::make();
        $middlewareForThree = FakeMiddleware::make();

        $this->execute(
            Workflow::make('test-workflow')
                ->addMiddleware(NodeTwo::class, fn (): FakeMiddleware => $middlewareForTwo)
                ->addMiddleware(NodeThree::class, fn (): FakeMiddleware => $middlewareForThree)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $middlewareForTwo->assertBeforeCalledTimes(1);
        $middlewareForTwo->assertBeforeCalledForNode(NodeTwo::class);

        $middlewareForThree->assertBeforeCalledTimes(1);
        $middlewareForThree->assertBeforeCalledForNode(NodeThree::class);
    }

    public function test_after_middleware_runs_even_for_streaming_nodes(): void
    {
        $middleware = FakeMiddleware::make();

        $this->execute(
            Workflow::make('test-workflow')
                ->addMiddleware(NodeTwo::class, fn (): FakeMiddleware => $middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $middleware->assertBeforeCalled();
        $middleware->assertAfterCalled();

        // after() sees the event the generator returned, not the one it yielded.
        $afterRecords = $middleware->getAfterRecords();
        $this->assertCount(1, $afterRecords);
        $this->assertInstanceOf(SecondEvent::class, $afterRecords[0]->event);
        $this->assertSame('Second complete', $afterRecords[0]->event->message);
    }

    public function test_node_middleware_matches_subclasses_via_instanceof(): void
    {
        $middleware = FakeMiddleware::make();

        $workflow = Workflow::make('test-workflow');
        $workflow->addMiddleware(NodeOne::class, fn (): FakeMiddleware => $middleware);

        // A subclass of NodeOne inherits the middleware registered against its parent.
        $child = new class () extends NodeOne {
        };

        $resolved = ExecutionTestFactory::graph($workflow->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]))->middlewareFor($child);

        $this->assertCount(1, $resolved);
        $this->assertSame($middleware, $resolved[0]);
    }

    public function test_node_middleware_does_not_match_unrelated_sibling_classes(): void
    {
        $middleware = FakeMiddleware::make();

        $workflow = Workflow::make('test-workflow');
        $workflow->addMiddleware(NodeTwo::class, fn (): FakeMiddleware => $middleware);

        // NodeOne is a sibling of NodeTwo, not a subclass — no match.
        $resolved = ExecutionTestFactory::graph($workflow->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]))->middlewareFor(new NodeOne());

        $this->assertSame([], $resolved);
    }

    public function test_tool_node_middleware_covers_parallel_tool_node_subclass(): void
    {
        // A safety middleware attached to ToolNode must also cover its
        // ParallelToolNode subclass — never silently dropped by an
        // execution-mode switch (see CONTEXT.md).
        $middleware = FakeMiddleware::make();

        $workflow = Workflow::make('test-workflow');
        $workflow->addMiddleware(ToolNode::class, fn (): FakeMiddleware => $middleware);

        $resolved = ExecutionTestFactory::graph($workflow->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]))->middlewareFor(new ParallelToolNode());

        $this->assertCount(1, $resolved);
        $this->assertSame($middleware, $resolved[0]);
    }

    public function test_node_middleware_registered_for_a_parent_runs_for_a_subclass_node(): void
    {
        $middleware = FakeMiddleware::make();
        $child = new class () extends NodeOne {
        };

        $this->execute(
            Workflow::make('test-workflow')
                ->addMiddleware(NodeOne::class, fn (): FakeMiddleware => $middleware)
                ->addNodes([$child, new NodeTwo(), new NodeThree()])
        );

        $middleware->assertBeforeCalledTimes(1);
        $this->assertInstanceOf(NodeOne::class, $middleware->getBeforeRecords()[0]->node);
        $this->assertNotSame(NodeOne::class, $middleware->getBeforeRecords()[0]->node::class);
    }

    public function test_middleware_receive_the_executing_node_and_its_state(): void
    {
        $seen = [];
        $middleware = FakeMiddleware::make()
            ->setBeforeHandler(function (NodeInterface $node, Event $event, WorkflowState $state) use (&$seen): void {
                $seen[] = ['before', $node, $state];
            })
            ->setAfterHandler(function (NodeInterface $node, Event $event, WorkflowState $state) use (&$seen): void {
                $state->set('seen_by_after', $state->get('node_one_executed'));
                $seen[] = ['after', $node, $state];
            });

        $finalState = $this->execute(
            Workflow::make('test-workflow')
                ->addMiddleware(NodeOne::class, $middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $this->assertCount(2, $seen);
        $this->assertSame($seen[0][1], $seen[1][1]);
        $this->assertSame($seen[0][2], $seen[1][2]);
        $this->assertTrue($finalState->get('seen_by_after'));
    }

    public function test_before_can_reshape_the_event_the_node_receives(): void
    {
        $middleware = FakeMiddleware::make()
            ->setBeforeHandler(function (NodeInterface $node, Event $event): void {
                if ($event instanceof FirstEvent) {
                    $event->message = 'reshaped by middleware';
                }
            });

        $finalState = $this->execute(
            Workflow::make('test-workflow')
                ->addMiddleware(NodeTwo::class, $middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $this->assertSame('reshaped by middleware', $finalState->get('first_message'));
    }

    public function test_a_failing_before_fails_the_run_with_its_exception(): void
    {
        $failure = new RuntimeException('middleware refused');
        $guard = FakeMiddleware::make()->setThrowOnBefore($failure);
        $observer = FakeMiddleware::make();
        $workflow = Workflow::make('failing-middleware')
            ->addMiddleware(NodeTwo::class, [$guard, $observer])
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        try {
            $this->execute($workflow);
            $this->fail('The middleware failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame(WorkflowStatus::Failed, $workflow->inspect()?->status);
    }

    public function test_later_middleware_and_the_node_do_not_run_after_a_failing_before(): void
    {
        $order = [];
        $guard = FakeMiddleware::make()->setThrowOnBefore(new RuntimeException('refused'));
        $later = FakeMiddleware::make()->setBeforeHandler(function () use (&$order): void {
            $order[] = 'later.before';
        });
        $node = new class () extends Node {
            public bool $invoked = false;

            public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent
            {
                $this->invoked = true;
                return new SecondEvent();
            }
        };
        $workflow = Workflow::make('failing-middleware')
            ->addGlobalMiddleware(fn (): FakeMiddleware => $guard)
            ->addGlobalMiddleware(fn (): FakeMiddleware => $later)
            ->addNodes([fn (): NodeInterface => $node, new NodeThree()])
            ->setStartEvent(new FirstEvent());

        try {
            $this->execute($workflow);
            $this->fail('The middleware failure must propagate.');
        } catch (RuntimeException) {
        }

        $this->assertSame([], $order);
        $this->assertFalse($node->invoked);
        $guard->assertAfterNotCalled();
    }

    public function test_after_waits_for_a_suspended_node_to_complete(): void
    {
        $middleware = FakeMiddleware::make();
        $workflow = Workflow::make('suspending-node')
            ->addMiddleware(InterruptableNode::class, fn (): FakeMiddleware => $middleware)
            ->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]);

        $this->assertTrue($this->execute($workflow)->isInterrupted());
        $middleware->assertBeforeCalledTimes(1);
        $middleware->assertAfterNotCalled();

        $this->assertFalse($this->resume($workflow)->isInterrupted());
        $middleware->assertBeforeCalledTimes(2);
        $middleware->assertAfterCalledTimes(1);
        $result = $middleware->getAfterRecords()[0]->event;
        $this->assertInstanceOf(SecondEvent::class, $result);
        $this->assertSame('Continued after interrupt', $result->message);
    }

    public function test_factories_run_once_per_segment_and_instances_are_cloned_from_their_prototype(): void
    {
        $factoryCalls = 0;
        $calls = 0;
        $prototype = FakeMiddleware::make()->setBeforeHandler(function () use (&$calls): void {
            $calls++;
        });
        $workflow = Workflow::make('prototypes')
            ->addGlobalMiddleware(function () use (&$factoryCalls): FakeMiddleware {
                $factoryCalls++;
                return FakeMiddleware::make();
            })
            ->addMiddleware(NodeOne::class, $prototype)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $this->execute($workflow);
        $this->execute($workflow);

        $this->assertSame(2, $factoryCalls);
        $this->assertSame(2, $calls);
        $prototype->assertNotCalled();
    }

    public function test_hook_middleware_run_before_added_middleware(): void
    {
        $order = [];
        $record = function (string $name) use (&$order): FakeMiddleware {
            return FakeMiddleware::make()->setBeforeHandler(function () use (&$order, $name): void {
                $order[] = $name;
            });
        };
        $workflow = new class ($record('hook.global'), $record('hook.node')) extends Workflow {
            public function __construct(
                protected FakeMiddleware $hookGlobal,
                protected FakeMiddleware $hookNode,
            ) {
                parent::__construct('hooks');
            }

            protected function globalMiddleware(): array
            {
                return [$this->hookGlobal];
            }

            protected function middleware(): array
            {
                return [NodeOne::class => [$this->hookNode]];
            }
        };
        $workflow->addGlobalMiddleware($record('added.global'))
            ->addMiddleware(NodeOne::class, $record('added.node'))
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $this->execute($workflow);

        $this->assertSame([
            'hook.global', 'added.global', 'hook.node', 'added.node',
            'hook.global', 'added.global',
            'hook.global', 'added.global',
        ], $order);
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function invalidRegistrations(): iterable
    {
        yield 'global object' => ['global', [new stdClass()]];
        yield 'node string' => [NodeOne::class, ['not middleware']];
    }

    /** @param array<mixed> $entries */
    #[DataProvider('invalidRegistrations')]
    public function test_a_value_that_is_not_middleware_is_rejected(string $target, array $entries): void
    {
        $workflow = Workflow::make();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Middleware must be an instance of WorkflowMiddleware');

        $target === 'global' ? $workflow->addGlobalMiddleware($entries) : $workflow->addMiddleware($target, $entries);
    }
}
