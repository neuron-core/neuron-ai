<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Middleware;

use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Tests\Workflow\Stub\SecondEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function array_merge;

class WorkflowMiddlewareTest extends TestCase
{
    use ExecutorTestHelpers;

    public function test_global_middleware_is_called_for_every_node(): void
    {
        $middleware = FakeMiddleware::make();

        $this->execute(
            Workflow::make()
                ->addGlobalMiddleware($middleware)
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
            Workflow::make()
                ->addMiddleware(NodeOne::class, $middleware)
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
            Workflow::make()
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
            Workflow::make()
                ->addGlobalMiddleware($middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $beforeRecords = $middleware->getBeforeRecords();

        $this->assertInstanceOf(StartEvent::class, $beforeRecords[0]->event);
        $this->assertInstanceOf(FirstEvent::class, $beforeRecords[1]->event);
        $this->assertInstanceOf(SecondEvent::class, $beforeRecords[2]->event);
    }

    public function test_after_receives_node_return_event(): void
    {
        $middleware = FakeMiddleware::make();

        $this->execute(
            Workflow::make()
                ->addGlobalMiddleware($middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $afterRecords = $middleware->getAfterRecords();

        $this->assertInstanceOf(FirstEvent::class, $afterRecords[0]->event);
        $this->assertInstanceOf(SecondEvent::class, $afterRecords[1]->event);
    }

    public function test_global_and_node_middleware_combine(): void
    {
        $global = FakeMiddleware::make();
        $nodeSpecific = FakeMiddleware::make();

        $this->execute(
            Workflow::make()
                ->addGlobalMiddleware($global)
                ->addMiddleware(NodeTwo::class, $nodeSpecific)
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
            Workflow::make()
                ->addGlobalMiddleware($global)
                ->addMiddleware(NodeOne::class, $nodeSpecific)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $this->assertSame('global.before', $order[0]);
        $this->assertSame('node.before', $order[1]);
        $this->assertSame('global.after', $order[2]);
        $this->assertSame('node.after', $order[3]);
    }

    public function test_middleware_can_read_and_write_state(): void
    {
        $middleware = FakeMiddleware::make()
            ->setBeforeHandler(function (NodeInterface $node, Event $event, WorkflowState $state): void {
                $state->set('injected_by_middleware', true);
            });

        $finalState = $this->execute(
            Workflow::make()
                ->addMiddleware(NodeOne::class, $middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $this->assertTrue($finalState->get('injected_by_middleware'));
    }

    public function test_middleware_on_multiple_node_classes(): void
    {
        $middleware = FakeMiddleware::make();

        $this->execute(
            Workflow::make()
                ->addMiddleware([NodeOne::class, NodeThree::class], $middleware)
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
            Workflow::make()
                ->addMiddleware(NodeTwo::class, $middlewareForTwo)
                ->addMiddleware(NodeThree::class, $middlewareForThree)
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
            Workflow::make()
                ->addMiddleware(NodeTwo::class, $middleware)
                ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $middleware->assertBeforeCalled();
        $middleware->assertAfterCalled();

        $afterRecords = $middleware->getAfterRecords();
        $this->assertInstanceOf(SecondEvent::class, $afterRecords[0]->event);
    }

    public function test_node_middleware_matches_subclasses_via_instanceof(): void
    {
        $middleware = FakeMiddleware::make();

        $workflow = Workflow::make();
        $workflow->addMiddleware(NodeOne::class, $middleware);

        // A subclass of NodeOne inherits the middleware registered against its parent.
        $child = new class () extends NodeOne {
        };

        $resolved = $workflow->getMiddlewareForNode($child);

        $this->assertCount(1, $resolved);
        $this->assertSame($middleware, $resolved[0]);
    }

    public function test_node_middleware_does_not_match_unrelated_sibling_classes(): void
    {
        $middleware = FakeMiddleware::make();

        $workflow = Workflow::make();
        $workflow->addMiddleware(NodeTwo::class, $middleware);

        // NodeOne is a sibling of NodeTwo, not a subclass — no match.
        $resolved = $workflow->getMiddlewareForNode(new NodeOne());

        $this->assertSame([], $resolved);
    }

    public function test_tool_node_middleware_covers_parallel_tool_node_subclass(): void
    {
        // A safety middleware attached to ToolNode must also cover its
        // ParallelToolNode subclass — never silently dropped by an
        // execution-mode switch (see CONTEXT.md).
        $middleware = FakeMiddleware::make();

        $workflow = Workflow::make();
        $workflow->addMiddleware(ToolNode::class, $middleware);

        $resolved = $workflow->getMiddlewareForNode(new ParallelToolNode(new InMemoryChatHistory()));

        $this->assertCount(1, $resolved);
        $this->assertSame($middleware, $resolved[0]);
    }
}
