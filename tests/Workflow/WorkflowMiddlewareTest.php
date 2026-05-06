<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Tests\Workflow\Stubs\SecondEvent;
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

    public function testGlobalMiddlewareIsCalledForEveryNode(): void
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

    public function testNodeSpecificMiddlewareOnlyRunsForTargetNode(): void
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

    public function testBeforeReceivesCorrectEventPerNode(): void
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

    public function testAfterReceivesNodeReturnEvent(): void
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

    public function testGlobalAndNodeMiddlewareCombine(): void
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

    public function testMiddlewareCanReadAndWriteState(): void
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

    public function testMiddlewareOnMultipleNodeClasses(): void
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

    public function testNodeMiddlewareIsOnlyCalledForItsNode(): void
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

    public function testAfterMiddlewareRunsEvenForStreamingNodes(): void
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
}
