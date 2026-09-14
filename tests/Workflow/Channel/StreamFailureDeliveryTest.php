<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use Error;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Workflow\Channel\Stub\ChunkStreamingNode;
use NeuronAI\Tests\Workflow\Channel\Stub\FailingStreamNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageFirstForkNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\StreamingImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessNode;
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_column;
use function array_key_last;
use function array_map;
use function json_decode;
use function json_encode;

class StreamFailureDeliveryTest extends TestCase
{
    /**
     * @param list<string> $expectedTypes
     */
    #[DataProvider('failed_streams')]
    public function test_failure_frames_reach_pull_and_push_consumers(
        StreamAdapterInterface $adapter,
        bool $emitChunk,
        array $expectedTypes,
    ): void {
        $error = new RuntimeException('Provider unavailable', 503);
        $channel = new FakeChannel();
        $workflow = Workflow::make()
            ->addNodes([new FailingStreamNode($error, $emitChunk)])
            ->setStreamAdapter($adapter)
            ->setChannel($channel);

        $pulled = [];
        $caught = null;
        try {
            foreach ($workflow->events() as $line) {
                $pulled[] = $line;
            }
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($error, $caught);
        $this->assertSame($pulled, $channel->getSent());
        $this->assertCount(1, $channel->getFailures());
        $this->assertSame($error, $channel->getFailures()[0]->exception);
        $this->assertSame([], $channel->getCompletions());
        $this->assertSame([], $channel->getSuspensions());

        $events = array_map(static fn (ProtocolEvent $event): array => json_decode(json_encode($event), true), $pulled);
        $this->assertSame($expectedTypes, array_column($events, 'type'));
        $this->assertSame(
            $adapter instanceof AGUIAdapter
                ? ['type' => 'RUN_ERROR', 'message' => 'The run failed.', 'code' => '503']
                : ['type' => 'error', 'errorText' => 'The run failed.'],
            $events[array_key_last($events)],
        );
    }

    /**
     * @return iterable<string, array{StreamAdapterInterface, bool, list<string>}>
     */
    public static function failed_streams(): iterable
    {
        yield 'AG-UI before output' => [new AGUIAdapter('thread_test'), false, ['RUN_STARTED', 'RUN_ERROR']];
        yield 'AG-UI after output' => [new AGUIAdapter('thread_test'), true, [
            'RUN_STARTED', 'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END', 'RUN_ERROR',
        ]];
        yield 'Vercel before output' => [new VercelAIAdapter(), false, ['error']];
        yield 'Vercel after output' => [new VercelAIAdapter(), true, ['start', 'text-start', 'text-delta', 'text-end', 'error']];
    }

    public function test_custom_adapter_receives_original_throwable_during_run(): void
    {
        $error = new Error('Node failed');
        $adapter = $this->createMock(StreamAdapterInterface::class);
        $adapter->expects($this->once())->method('start')->willReturn([]);
        $failed = new ProtocolEvent('failed');
        $adapter->expects($this->once())->method('error')->with($this->identicalTo($error))->willReturn([$failed]);
        $adapter->expects($this->never())->method('end');
        $channel = new FakeChannel();
        $workflow = Workflow::make()
            ->addNodes([new FailingStreamNode($error, false)])
            ->setStreamAdapter($adapter)
            ->setChannel($channel);

        $caught = null;
        try {
            $workflow->run();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($error, $caught);
        $this->assertSame([$failed], $channel->getSent());
        $this->assertCount(1, $channel->getFailures());
        $this->assertSame($error, $channel->getFailures()[0]->exception);
        $this->assertSame([], $channel->getCompletions());
    }

    public function test_a_failure_raised_by_the_adapter_settles_the_run_as_failed(): void
    {
        $error = new RuntimeException('adapter cannot encode');
        $persistence = new InMemoryPersistence();
        $observed = [];
        $workflow = Workflow::make(workflowId: 'adapter-failure')
            ->setPersistence($persistence)
            ->addNodes([new ChunkStreamingNode(2)])
            ->setStreamAdapter($this->adapterFailingWith($error))
            ->subscribe(AgentError::class, function (AgentError $event) use (&$observed): void {
                $observed[] = $event->exception;
            });

        $caught = null;
        try {
            $workflow->run();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        // The failure was raised outside the executor generator, yet the run
        // settles exactly as for a failing node: the generation is marked
        // failed, observed, and the next ignition supersedes it.
        $this->assertSame($error, $caught);
        $this->assertSame([$error], $observed);
        $this->assertSame(WorkflowStatus::Failed, (new WorkflowExecutor())->inspect($workflow)->status);

        $state = Workflow::make(workflowId: 'adapter-failure')
            ->setPersistence($persistence)
            ->addNodes([new ChunkStreamingNode(1)])
            ->run();

        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
    }

    public function test_a_failure_raised_by_the_adapter_settles_a_parallel_run_as_failed(): void
    {
        $error = new RuntimeException('adapter cannot encode');
        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make(workflowId: 'parallel-adapter-failure')
            ->setPersistence($persistence)
            ->setExecutor(new AsyncExecutor())
            ->addNodes([new ImageFirstForkNode(), new StreamingImageProcessNode(), new TextProcessNode(), new MergeNode()])
            ->setStreamAdapter($this->adapterFailingWith($error));

        $caught = null;
        try {
            $workflow->run();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        // The async executor drains the sibling branch before it surfaces the
        // injected failure, so the run still ends up failed, not running.
        $this->assertSame($error, $caught);
        $this->assertSame(WorkflowStatus::Failed, (new WorkflowExecutor())->inspect($workflow)->status);
    }

    protected function adapterFailingWith(Throwable $error): StreamAdapterInterface
    {
        $adapter = $this->createMock(StreamAdapterInterface::class);
        $adapter->method('start')->willReturn([]);
        $adapter->method('transform')->willThrowException($error);
        $adapter->method('error')->willReturn([]);

        return $adapter;
    }
}
