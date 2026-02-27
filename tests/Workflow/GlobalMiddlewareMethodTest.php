<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class GlobalMiddlewareMethodTest extends TestCase
{
    public function testGlobalMiddlewareOverrideRunsOnAllNodes(): void
    {
        $middleware = FakeMiddleware::make();

        // Test using a workflow class that overrides globalMiddleware()
        $workflow = new class ($middleware) extends Workflow {
            public function __construct(
                private readonly FakeMiddleware $middleware,
            ) {
                parent::__construct();
            }

            protected function nodes(): array
            {
                return [new NodeOne(), new NodeTwo(), new NodeThree()];
            }

            protected function globalMiddleware(): array
            {
                return [$this->middleware];
            }
        };

        $workflow->init()->run();

        // 3 nodes = 3 before + 3 after
        $middleware->assertBeforeCalledTimes(3);
        $middleware->assertAfterCalledTimes(3);
        $middleware->assertCallCount(6);
    }

    public function testGlobalMiddlewareOverrideRunsWhenMiddlewareReturnsEmpty(): void
    {
        $middleware = FakeMiddleware::make();

        // Even without defining middleware(), globalMiddleware() should work
        $workflow = new class ($middleware) extends Workflow {
            public function __construct(
                private readonly FakeMiddleware $middleware,
            ) {
                parent::__construct();
            }

            protected function nodes(): array
            {
                return [new NodeOne(), new NodeTwo(), new NodeThree()];
            }

            protected function globalMiddleware(): array
            {
                return [$this->middleware];
            }

            // Note: middleware() not overridden (returns [])
        };

        $workflow->init()->run();

        // Should still run on all 3 nodes
        $middleware->assertBeforeCalledTimes(3);
        $middleware->assertAfterCalledTimes(3);
    }

    public function testGlobalMiddlewareOverrideCombinesWithNodeMiddlewareOverride(): void
    {
        $global = FakeMiddleware::make();
        $nodeSpecific = FakeMiddleware::make();

        $workflow = new class ($global, $nodeSpecific) extends Workflow {
            public function __construct(
                private readonly FakeMiddleware $global,
                private readonly FakeMiddleware $nodeSpecific,
            ) {
                parent::__construct();
            }

            protected function nodes(): array
            {
                return [new NodeOne(), new NodeTwo(), new NodeThree()];
            }

            protected function globalMiddleware(): array
            {
                return [$this->global];
            }

            protected function middleware(): array
            {
                return [
                    NodeTwo::class => $this->nodeSpecific,
                ];
            }
        };

        $workflow->init()->run();

        // Global middleware runs on all 3 nodes
        $global->assertBeforeCalledTimes(3);
        $global->assertAfterCalledTimes(3);

        // Node-specific middleware runs only on NodeTwo
        $nodeSpecific->assertBeforeCalledTimes(1);
        $nodeSpecific->assertAfterCalledTimes(1);
    }

    public function testGlobalMiddlewareOverrideExecutesInCorrectOrder(): void
    {
        $order = [];

        $global = FakeMiddleware::make()
            ->setBeforeHandler(function () use (&$order): void {
                $order[] = 'global.before';
            })
            ->setAfterHandler(function () use (&$order): void {
                $order[] = 'global.after';
            });

        $node = FakeMiddleware::make()
            ->setBeforeHandler(function () use (&$order): void {
                $order[] = 'node.before';
            })
            ->setAfterHandler(function () use (&$order): void {
                $order[] = 'node.after';
            });

        $workflow = new class ($global, $node) extends Workflow {
            public function __construct(
                private readonly FakeMiddleware $global,
                private readonly FakeMiddleware $node,
            ) {
                parent::__construct();
            }

            protected function nodes(): array
            {
                return [new NodeOne(), new NodeTwo(), new NodeThree()];
            }

            protected function globalMiddleware(): array
            {
                return [$this->global];
            }

            protected function middleware(): array
            {
                return [
                    NodeOne::class => $this->node,
                ];
            }
        };

        $workflow->init()->run();

        // Order per node: global.before → node.before → [node] → global.after → node.after
        // NodeOne has node-specific middleware, NodeTwo/Three only have global
        $this->assertSame([
            // NodeOne
            'global.before', 'node.before', 'global.after', 'node.after',
            // NodeTwo (only global)
            'global.before', 'global.after',
            // NodeThree (only global)
            'global.before', 'global.after',
        ], $order);
    }

    public function testGlobalMiddlewareOverrideReceivesCorrectEvents(): void
    {
        $middleware = FakeMiddleware::make();

        $workflow = new class ($middleware) extends Workflow {
            public function __construct(
                private readonly FakeMiddleware $middleware,
            ) {
                parent::__construct();
            }

            protected function nodes(): array
            {
                return [new NodeOne(), new NodeTwo(), new NodeThree()];
            }

            protected function globalMiddleware(): array
            {
                return [$this->middleware];
            }
        };

        $workflow->init()->run();

        $beforeRecords = $middleware->getBeforeRecords();

        // Each before() should receive the correct event for that node
        $this->assertCount(3, $beforeRecords);
        $this->assertInstanceOf(Event::class, $beforeRecords[0]->event);
        $this->assertInstanceOf(Event::class, $beforeRecords[1]->event);
        $this->assertInstanceOf(Event::class, $beforeRecords[2]->event);

        $afterRecords = $middleware->getAfterRecords();
        $this->assertCount(3, $afterRecords);
    }

    public function testGlobalMiddlewareOverrideCanReadAndWriteState(): void
    {
        $middleware = FakeMiddleware::make()
            ->setBeforeHandler(function (NodeInterface $node, Event $event, WorkflowState $state): void {
                $state->set('injected_by_global', true);
                $state->set('execution_count', ($state->get('execution_count') ?? 0) + 1);
            });

        $workflow = new class ($middleware) extends Workflow {
            public function __construct(
                private readonly FakeMiddleware $middleware,
            ) {
                parent::__construct();
            }

            protected function nodes(): array
            {
                return [new NodeOne(), new NodeTwo(), new NodeThree()];
            }

            protected function globalMiddleware(): array
            {
                return [$this->middleware];
            }
        };

        $finalState = $workflow->init()->run();

        $this->assertTrue($finalState->get('injected_by_global'));
        $this->assertEquals(3, $finalState->get('execution_count'));
    }

    public function testEmptyGlobalMiddlewareOverrideDoesNotCauseErrors(): void
    {
        // A workflow that explicitly returns empty array should work fine
        $workflow = new class () extends Workflow {
            protected function nodes(): array
            {
                return [new NodeOne(), new NodeTwo(), new NodeThree()];
            }

            protected function globalMiddleware(): array
            {
                return [];
            }
        };

        $finalState = $workflow->init()->run();

        // Workflow should complete normally
        $this->assertTrue($finalState->get('node_one_executed'));
        $this->assertTrue($finalState->get('node_two_executed'));
        $this->assertTrue($finalState->get('node_three_executed'));
    }

    public function testMultipleGlobalMiddlewareInOverrideRunInOrder(): void
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

        $workflow = new class ($first, $second) extends Workflow {
            public function __construct(
                private readonly FakeMiddleware $first,
                private readonly FakeMiddleware $second,
            ) {
                parent::__construct();
            }

            protected function nodes(): array
            {
                return [new NodeOne(), new NodeTwo(), new NodeThree()];
            }

            protected function globalMiddleware(): array
            {
                return [$this->first, $this->second];
            }
        };

        $workflow->init()->run();

        // Order per node: first.before → second.before → [node] → first.after → second.after
        // 3 nodes, so pattern repeats 3 times
        $this->assertSame(
            [
                // NodeOne
                'first.before', 'second.before', 'first.after', 'second.after',
                // NodeTwo
                'first.before', 'second.before', 'first.after', 'second.after',
                // NodeThree
                'first.before', 'second.before', 'first.after', 'second.after',
            ],
            $order
        );
    }
}
