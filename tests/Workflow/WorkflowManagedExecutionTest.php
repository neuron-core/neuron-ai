<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\StaleWorkflowRunException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Workflow\Channel\Stub\ChunkAdapter;
use NeuronAI\Tests\Workflow\Channel\Stub\ChunkStreamingNode;
use NeuronAI\Tests\Workflow\Executor\Stub\CrashAfterInitialization;
use NeuronAI\Tests\Workflow\Executor\Stub\MemoizingNode;
use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Executor\SequentialBranchRunner;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;

use function iterator_to_array;
use function serialize;
use function str_repeat;

class WorkflowManagedExecutionTest extends TestCase
{
    public function test_declared_identity_and_staged_reservation_do_not_initialize_a_run(): void
    {
        $store = new InMemoryPersistence();
        $workflow = KeyedWorkflow::make()->withDeclaredWorkflowId('order/42')->setPersistence($store);
        self::assertSame('order/42', $workflow->getWorkflowId());
        $before = serialize($store);
        $request = ExecutionRequest::start(new StartEvent(), 'reserved-42');
        self::assertNull($workflow->inspect()?->runId);
        self::assertNull($workflow->inspect());
        self::assertSame($before, serialize($store));
        $state = $workflow->run($request);
        self::assertSame('reserved-42', $state->getRunId());
        self::assertSame(1, $state->getExecutionAttempt());
        $serializer = new PhpSerializer();
        foreach (['__control', '__ignition'] as $key) {
            self::assertSame('reserved-42', $serializer->unserialize($store->get('order/42', $key))->runId);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidReservations(): iterable
    {
        foreach (['', '__control', '../other', 'run/id', "run\n", str_repeat('x', 129)] as $index => $value) {
            yield (string) $index => [$value];
        }
    }

    #[DataProvider('invalidReservations')]
    public function test_invalid_reservation_is_rejected_before_persistence(string $id): void
    {
        $this->expectException(WorkflowException::class);
        ExecutionRequest::start(new StartEvent(), $id);
    }

    public function test_a_different_reservation_cannot_take_a_held_address(): void
    {
        $store = new InMemoryPersistence();
        $make = fn (): KeyedWorkflow => KeyedWorkflow::make('order')->setPersistence($store);
        $make()->run(ExecutionRequest::start(new StartEvent(), 'reserved-one'));
        try {
            $make()->run(ExecutionRequest::start(new StartEvent(), 'reserved-two'));
            self::fail('Another reservation must not replace the held run.');
        } catch (RunInFlightException $error) {
            self::assertSame('reserved-one', $error->runId);
        }
    }

    public function test_resume_recovers_a_reserved_run_after_a_lost_initialization_acknowledgement(): void
    {
        $store = new CrashAfterInitialization();
        $make = fn (): Workflow => Workflow::make('order')->setPersistence($store)->addNode(new MemoizingNode());
        try {
            $make()->run(ExecutionRequest::start(new StartEvent(), 'reserved'));
            self::fail('Expected interrupted initialization.');
        } catch (RuntimeException $e) {
            self::assertSame('Lost initialization acknowledgement', $e->getMessage());
        }
        $state = $make()->run(ExecutionRequest::resume());
        self::assertSame('reserved', $state->getRunId());
        self::assertSame(2, $state->getExecutionAttempt());
    }

    public function test_reserved_start_cannot_implicitly_replace_or_recover_a_failure(): void
    {
        $store = new InMemoryPersistence();
        $failed = Workflow::make('order')->setPersistence($store)->addNode(new MemoizingNode(true));
        try {
            $failed->run(ExecutionRequest::start(new StartEvent(), 'first'));
        } catch (RuntimeException) {
        }
        $this->expectException(RunInFlightException::class);
        Workflow::make('order')->setPersistence($store)->addNode(new MemoizingNode())->run(ExecutionRequest::start(new StartEvent(), 'second'));
    }

    /** @return iterable<string, array{bool}> */
    public static function executors(): iterable
    {
        yield 'sequential' => [false];
        yield 'async' => [true];
    }

    #[DataProvider('executors')]
    public function test_resource_factory_runs_for_the_admitted_run_and_builds_the_push_pipeline(bool $async): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make('order')->setBranchRunner($async ? new AsyncBranchRunner() : new SequentialBranchRunner())
            ->addNode(new ChunkStreamingNode(2))->setChannel(fn (): StreamingChannelInterface => $channel);
        $workflow->setStreamAdapter(function () use ($workflow, $channel): ChunkAdapter {
            self::assertSame('order', $workflow->getWorkflowId());
            self::assertSame('reserved', $workflow->inspect()->runId);
            self::assertSame(1, $workflow->inspect()->executionAttempt);
            self::assertSame(WorkflowStatus::Running, $workflow->inspect()->status);
            self::assertSame([], $channel->getSent());
            return new ChunkAdapter();
        });
        $state = $workflow->run(ExecutionRequest::start(new StartEvent(), 'reserved'));
        self::assertCount(2, $channel->getSent());
        self::assertSame('reserved', $state->getRunId());
        $channel->assertCompleted();
    }

    public function test_pull_resource_factories_remain_lazy(): void
    {
        $calls = 0;
        $workflow = Workflow::make('order')->addNode(new ChunkStreamingNode(2))
            ->setStreamAdapter(function () use (&$calls): ChunkAdapter {
                $calls++;
                return new ChunkAdapter();
            });
        $stream = $workflow->events();
        self::assertSame(0, $calls);
        self::assertNull($workflow->inspect());
        self::assertCount(2, iterator_to_array($stream));
        self::assertSame(1, $calls);
        self::assertSame(WorkflowStatus::Completed, $stream->getReturn()->getStatus());
    }

    public function test_factories_run_on_owned_continuations_but_not_saved_outcomes_or_early_polls(): void
    {
        $store = new InMemoryPersistence();
        $calls = 0;
        $factory = function () use (&$calls): ChunkAdapter {
            $calls++;
            return new ChunkAdapter();
        };
        $make = fn (): KeyedWorkflow => KeyedWorkflow::make('order')->setPersistence($store)->retainCompletionUntilAcknowledged()->setStreamAdapter($factory);
        $state = $make()->run(ExecutionRequest::start(new StartEvent(), 'reserved'));
        $make()->run(ExecutionRequest::resume());
        self::assertSame(1, $calls);
        $make()->run(ExecutionRequest::resume([], $state->getRunId(), $state->getExecutionAttempt()));
        self::assertSame(2, $calls);
        $make()->run(ExecutionRequest::resume());
        self::assertSame(2, $calls);
    }

    public function test_factory_failure_is_fenced_and_requires_explicit_recovery(): void
    {
        $store = new InMemoryPersistence();
        $make = fn (): Workflow => Workflow::make('order')->setPersistence($store)->addNode(new MemoizingNode());
        $workflow = $make()->setStreamAdapter(function (): never {
            throw new RuntimeException('configuration failed');
        });
        try {
            $workflow->run(ExecutionRequest::start(new StartEvent(), 'reserved'));
            self::fail('Expected setup failure.');
        } catch (RuntimeException $e) {
            self::assertSame('configuration failed', $e->getMessage());
        }
        self::assertSame(WorkflowStatus::Failed, $workflow->inspect()->status);
        $state = $make()->run(ExecutionRequest::resume(expectedRunId: 'reserved', expectedExecutionAttempt: 1));
        self::assertSame('reserved', $state->getRunId());
        self::assertSame(2, $state->getExecutionAttempt());
    }

    public function test_factory_storage_changes_do_not_redirect_the_active_execution(): void
    {
        $store = new InMemoryPersistence();
        $nextStore = new InMemoryPersistence();
        $workflow = Workflow::make('order')->setPersistence($store)->addNode(new MemoizingNode())
            ->retainCompletionUntilAcknowledged();
        $workflow->setStreamAdapter(function () use ($workflow, $nextStore): ChunkAdapter {
            $workflow->setPersistence($nextStore);
            return new ChunkAdapter();
        });

        $state = $workflow->run(ExecutionRequest::start(new StartEvent(), 'reserved'));

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertNull($workflow->inspect());
        self::assertSame('reserved', Workflow::make('order')->setPersistence($store)->inspect()->runId);
    }

    public function test_stale_replacement_cannot_abandon_a_newer_attempt(): void
    {
        $store = new InMemoryPersistence();
        $workflow = Workflow::make('order')->setPersistence($store)->addNode(new MemoizingNode(true));
        try {
            $workflow->run(ExecutionRequest::start(new StartEvent(), 'reserved'));
        } catch (RuntimeException) {
        }
        try {
            $workflow->run(ExecutionRequest::resume());
        } catch (RuntimeException) {
        }
        self::assertSame(2, $workflow->inspect()->executionAttempt);
        try {
            $workflow->abandon('reserved', 1);
            self::fail('Expected stale attempt rejection.');
        } catch (WorkflowException) {
            self::assertSame(2, $workflow->inspect()->executionAttempt);
        }
        self::assertTrue($workflow->abandon('reserved', 2));
    }

    public function test_delayed_cleanup_cannot_remove_the_next_reserved_turn(): void
    {
        $store = new InMemoryPersistence();
        $make = fn (): Workflow => Workflow::make('order')->setPersistence($store)->addNode(new MemoizingNode())->retainCompletionUntilAcknowledged();
        $make()->run(ExecutionRequest::start(new StartEvent(), 'first'));
        $make()->acknowledge('first');
        $make()->run(ExecutionRequest::start(new StartEvent(), 'second'));
        $this->expectException(StaleWorkflowRunException::class);
        $make()->acknowledge('first');
    }
    public function test_zero_chunk_run_builds_resources_before_adapter_starts(): void
    {
        $channel = new FakeChannel();
        $workflow = Workflow::make('order')->addNode(new MemoizingNode())->setChannel(fn (): StreamingChannelInterface => $channel)
            ->setStreamAdapter(fn (): \NeuronAI\Agent\Adapters\AGUIAdapter => new \NeuronAI\Agent\Adapters\AGUIAdapter('order', 'client-run'));
        $state = $workflow->run(ExecutionRequest::start(new StartEvent(), 'reserved'));
        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame('client-run', $channel->getSent()[0]->data['runId']);
        $channel->assertCompleted();
    }

    public function test_factory_cannot_run_on_another_workers_live_lease(): void
    {
        $store = new InMemoryPersistence();
        $workflow = Workflow::make('order')->setPersistence($store)->setLeaseTimeout(300)->addNode(new ChunkStreamingNode());
        $stream = $workflow->events(ExecutionRequest::start(new StartEvent(), 'reserved'));
        $stream->current();
        $other = Workflow::make('order')->setPersistence($store)->setStreamAdapter(function (): never {
            self::fail('A refused worker cannot build resources.');
        });
        try {
            $other->run(ExecutionRequest::resume(expectedRunId: 'reserved', expectedExecutionAttempt: 1));
            self::fail('Expected live lease refusal.');
        } catch (WorkflowException) {
            self::assertSame(WorkflowStatus::Running, $workflow->inspect()->status);
        }
        iterator_to_array($stream);
    }

    public function test_resource_instances_are_created_for_each_invocation(): void
    {
        $calls = 0;
        $workflow = Workflow::make('order')->addNode(new ChunkStreamingNode(1))
            ->setStreamAdapter(function () use (&$calls): ChunkAdapter {
                $calls++;
                return new ChunkAdapter();
            });
        $stream = $workflow->events();
        self::assertSame(0, $calls);
        iterator_to_array($stream);
        self::assertSame(1, $calls);
        $workflow->run();
        self::assertSame(2, $calls);
    }

    public function test_adapter_start_failure_is_durable_before_error_output_without_running_nodes(): void
    {
        $store = new InMemoryPersistence();
        $workflow = Workflow::make('order')->setPersistence($store)->addNode(new \NeuronAI\Tests\Workflow\Stub\NodeOne());
        $workflow->setStreamAdapter(fn (): StreamAdapterInterface => new class () extends ChunkAdapter {
            public function start(): iterable
            {
                throw new RuntimeException('Cannot start adapter');
            }
            public function error(Throwable $error): iterable
            {
                yield new \NeuronAI\Workflow\Streaming\ProtocolEvent('error', []);
            }
        });
        $stream = $workflow->events(ExecutionRequest::start(runId: 'reserved'));
        self::assertSame('error', $stream->current()->type);
        self::assertSame(WorkflowStatus::Failed, $workflow->inspect()->status);
        self::assertNull($store->get('order', 'reserved/NodeOne_0'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot start adapter');
        $stream->next();
    }

}
