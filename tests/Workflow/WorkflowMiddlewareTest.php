<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function array_merge;

class WorkflowMiddlewareTest extends TestCase
{
    public function testGlobalMiddlewareIsCalledForEveryNode(): void
    {
        $middleware = FakeMiddleware::make();

        Workflow::make()
            ->addGlobalMiddleware($middleware)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->init()
            ->run();

        // 3 nodes = 3 before + 3 after
        $middleware->assertBeforeCalledTimes(3);
        $middleware->assertAfterCalledTimes(3);
        $middleware->assertCallCount(6);
    }

    public function testNodeSpecificMiddlewareOnlyRunsForTargetNode(): void
    {
        $middleware = FakeMiddleware::make();

        Workflow::make()
            ->addMiddleware(NodeOne::class, $middleware)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->init()
            ->run();

        $middleware->assertBeforeCalledTimes(1);
        $middleware->assertAfterCalledTimes(1);
        $middleware->assertBeforeCalledForNode(NodeOne::class);
        $middleware->assertAfterCalledForNode(NodeOne::class);
    }

    public function testMultipleGlobalMiddlewareExecuteInRegistrationOrder(): void
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

        Workflow::make()
            ->addGlobalMiddleware([$first, $second])
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->init()
            ->run();

        // For each node: first.before → second.before → [node] → first.after → second.after
        $expectedPerNode = [
            'first.before', 'second.before', 'first.after', 'second.after',
        ];

        // 3 nodes, same pattern repeated
        $expected = array_merge($expectedPerNode, $expectedPerNode, $expectedPerNode);

        $this->assertSame($expected, $order);
    }

    public function testBeforeReceivesCorrectEventPerNode(): void
    {
        $middleware = FakeMiddleware::make();

        Workflow::make()
            ->addGlobalMiddleware($middleware)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->init()
            ->run();

        $beforeRecords = $middleware->getBeforeRecords();

        // NodeOne handles StartEvent
        $this->assertInstanceOf(StartEvent::class, $beforeRecords[0]->event);
        // NodeTwo handles FirstEvent
        $this->assertInstanceOf(Stubs\FirstEvent::class, $beforeRecords[1]->event);
        // NodeThree handles SecondEvent
        $this->assertInstanceOf(Stubs\SecondEvent::class, $beforeRecords[2]->event);
    }

    public function testAfterReceivesNodeReturnEvent(): void
    {
        $middleware = FakeMiddleware::make();

        Workflow::make()
            ->addGlobalMiddleware($middleware)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->init()
            ->run();

        $afterRecords = $middleware->getAfterRecords();

        // NodeOne returns FirstEvent
        $this->assertInstanceOf(Stubs\FirstEvent::class, $afterRecords[0]->event);
        // NodeTwo returns SecondEvent
        $this->assertInstanceOf(Stubs\SecondEvent::class, $afterRecords[1]->event);
    }

    public function testGlobalAndNodeMiddlewareCombine(): void
    {
        $global = FakeMiddleware::make();
        $nodeSpecific = FakeMiddleware::make();

        Workflow::make()
            ->addGlobalMiddleware($global)
            ->addMiddleware(NodeTwo::class, $nodeSpecific)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->init()
            ->run();

        // Global runs on all 3 nodes
        $global->assertBeforeCalledTimes(3);
        $global->assertAfterCalledTimes(3);

        // Node-specific only runs on NodeTwo
        $nodeSpecific->assertBeforeCalledTimes(1);
        $nodeSpecific->assertAfterCalledTimes(1);
        $nodeSpecific->assertBeforeCalledForNode(NodeTwo::class);
    }

    public function testGlobalMiddlewareRunsBeforeNodeMiddleware(): void
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

        Workflow::make()
            ->addGlobalMiddleware($global)
            ->addMiddleware(NodeOne::class, $nodeSpecific)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->init()
            ->run();

        // For NodeOne: global runs first, then node-specific
        $this->assertSame('global.before', $order[0]);
        $this->assertSame('node.before', $order[1]);
        $this->assertSame('global.after', $order[2]);
        $this->assertSame('node.after', $order[3]);
    }

    public function testMiddlewareCanReadAndWriteState(): void
    {
        $middleware = FakeMiddleware::make()
            ->setBeforeHandler(function (NodeInterface $node, Event $event, WorkflowState $state): void {
                $state->set('injected_by_middleware', true);
            });

        $finalState = Workflow::make()
            ->addMiddleware(NodeOne::class, $middleware)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->init()
            ->run();

        $this->assertTrue($finalState->get('injected_by_middleware'));
    }

    public function testMiddlewareOnMultipleNodeClasses(): void
    {
        $middleware = FakeMiddleware::make();

        Workflow::make()
            ->addMiddleware([NodeOne::class, NodeThree::class], $middleware)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->init()
            ->run();

        $middleware->assertBeforeCalledTimes(2);
        $middleware->assertAfterCalledTimes(2);
        $middleware->assertBeforeCalledForNode(NodeOne::class);
        $middleware->assertBeforeCalledForNode(NodeThree::class);
    }

    public function testNodeMiddlewareIsOnlyCalledForItsNode(): void
    {
        $middlewareForTwo = FakeMiddleware::make();
        $middlewareForThree = FakeMiddleware::make();

        Workflow::make()
            ->addMiddleware(NodeTwo::class, $middlewareForTwo)
            ->addMiddleware(NodeThree::class, $middlewareForThree)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->init()
            ->run();

        $middlewareForTwo->assertBeforeCalledTimes(1);
        $middlewareForTwo->assertBeforeCalledForNode(NodeTwo::class);

        $middlewareForThree->assertBeforeCalledTimes(1);
        $middlewareForThree->assertBeforeCalledForNode(NodeThree::class);
    }

    public function testAfterMiddlewareRunsEvenForStreamingNodes(): void
    {
        $middleware = FakeMiddleware::make();

        // NodeTwo is a streaming node (returns Generator)
        Workflow::make()
            ->addMiddleware(NodeTwo::class, $middleware)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->init()
            ->run();

        $middleware->assertBeforeCalled();
        $middleware->assertAfterCalled();

        // after() should receive the final return value, not intermediate yields
        $afterRecords = $middleware->getAfterRecords();
        $this->assertInstanceOf(Stubs\SecondEvent::class, $afterRecords[0]->event);
    }
}
