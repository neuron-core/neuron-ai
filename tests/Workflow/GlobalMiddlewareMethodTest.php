<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Testing\MiddlewareRecord;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Tests\Workflow\Stub\SecondEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function array_map;

class GlobalMiddlewareMethodTest extends TestCase
{
    use ExecutorTestHelpers;

    public function test_global_middleware_override_runs_on_all_nodes(): void
    {
        $middleware = FakeMiddleware::make();

        $workflow = new class ($middleware) extends Workflow {
            public function __construct(
                protected readonly FakeMiddleware $middleware,
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

        $this->execute($workflow);

        $middleware->assertBeforeCalledTimes(3);
        $middleware->assertAfterCalledTimes(3);
        $middleware->assertCallCount(6);
    }

    public function test_global_middleware_hook_runs_for_every_segment_with_the_same_instance(): void
    {
        $middleware = FakeMiddleware::make();

        $workflow = new class ($middleware) extends Workflow {
            public int $hookCalls = 0;

            public function __construct(
                protected readonly FakeMiddleware $middleware,
            ) {
                parent::__construct();
            }

            protected function nodes(): array
            {
                return [new NodeOne(), new NodeTwo(), new NodeThree()];
            }

            protected function globalMiddleware(): array
            {
                $this->hookCalls++;
                return [$this->middleware];
            }
        };

        $this->execute($workflow);
        $this->execute($workflow);

        // Hook-created middleware are used directly, not cloned.
        $this->assertSame(2, $workflow->hookCalls);
        $middleware->assertBeforeCalledTimes(6);
    }

    public function test_global_middleware_override_combines_with_node_middleware_override(): void
    {
        $global = FakeMiddleware::make();
        $nodeSpecific = FakeMiddleware::make();

        $workflow = new class ($global, $nodeSpecific) extends Workflow {
            public function __construct(
                protected readonly FakeMiddleware $global,
                protected readonly FakeMiddleware $nodeSpecific,
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

        $this->execute($workflow);

        $global->assertBeforeCalledTimes(3);
        $global->assertAfterCalledTimes(3);

        $nodeSpecific->assertBeforeCalledTimes(1);
        $nodeSpecific->assertAfterCalledTimes(1);
    }

    public function test_global_middleware_override_executes_in_correct_order(): void
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
                protected readonly FakeMiddleware $global,
                protected readonly FakeMiddleware $node,
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

        $this->execute($workflow);

        $this->assertSame([
            'global.before', 'node.before', 'global.after', 'node.after',
            'global.before', 'global.after',
            'global.before', 'global.after',
        ], $order);
    }

    public function test_global_middleware_override_receives_correct_events(): void
    {
        $middleware = FakeMiddleware::make();

        $workflow = new class ($middleware) extends Workflow {
            public function __construct(
                protected readonly FakeMiddleware $middleware,
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

        $this->execute($workflow);

        $eventClasses = static fn (array $records): array => array_map(static fn (MiddlewareRecord $record): string => $record->event::class, $records);

        $this->assertSame([StartEvent::class, FirstEvent::class, SecondEvent::class], $eventClasses($middleware->getBeforeRecords()));
        $this->assertSame([FirstEvent::class, SecondEvent::class, StopEvent::class], $eventClasses($middleware->getAfterRecords()));
    }

    public function test_global_middleware_override_can_read_and_write_state(): void
    {
        $middleware = FakeMiddleware::make()
            ->setBeforeHandler(function (NodeInterface $node, Event $event, WorkflowState $state): void {
                $state->set('injected_by_global', true);
                $state->set('execution_count', ($state->get('execution_count') ?? 0) + 1);
            });

        $workflow = new class ($middleware) extends Workflow {
            public function __construct(
                protected readonly FakeMiddleware $middleware,
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

        $finalState = $this->execute($workflow);

        $this->assertTrue($finalState->get('injected_by_global'));
        $this->assertSame(3, $finalState->get('execution_count'));
    }

    public function test_empty_global_middleware_override_does_not_cause_errors(): void
    {
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

        $finalState = $this->execute($workflow);

        $this->assertTrue($finalState->get('node_one_executed'));
        $this->assertTrue($finalState->get('node_two_executed'));
        $this->assertTrue($finalState->get('node_three_executed'));
    }

    public function test_multiple_global_middleware_in_override_run_in_order(): void
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
                protected readonly FakeMiddleware $first,
                protected readonly FakeMiddleware $second,
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

        $this->execute($workflow);

        $this->assertSame(
            [
                'first.before', 'second.before', 'first.after', 'second.after',
                'first.before', 'second.before', 'first.after', 'second.after',
                'first.before', 'second.before', 'first.after', 'second.after',
            ],
            $order
        );
    }
}
