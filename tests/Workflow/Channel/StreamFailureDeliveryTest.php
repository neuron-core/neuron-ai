<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use Error;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Workflow\Channel\Stub\ChunkStreamingNode;
use NeuronAI\Tests\Workflow\Channel\Stub\FailingStreamNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageFirstForkNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\StreamingImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessNode;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Observability\WorkflowError;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
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
        bool $push,
    ): void {
        $error = new RuntimeException('Provider unavailable', 503);
        $channel = new FakeChannel();
        $workflow = Workflow::make()
            ->addNodes([new FailingStreamNode($error, $emitChunk)])
            ->setStreamAdapter(fn (): StreamAdapterInterface => $adapter)
            ->setChannel(fn (): ?StreamingChannelInterface => $push ? $channel : null);

        $pulled = [];
        $caught = null;
        try {
            if ($push) {
                $workflow->run();
            } else {
                foreach ($workflow->events() as $line) {
                    $pulled[] = $line;
                }
            }
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($error, $caught);
        if ($push) {
            $this->assertCount(1, $channel->getFailures());
            $this->assertSame($error, $channel->getFailures()[0]->exception);
        }
        $this->assertSame([], $channel->getCompletions());
        $this->assertSame([], $channel->getSuspensions());

        $events = array_map(static fn (ProtocolEvent $event): array => json_decode(json_encode($event), true), $push ? $channel->getSent() : $pulled);
        $this->assertSame($expectedTypes, array_column($events, 'type'));
        $this->assertSame(
            $adapter instanceof AGUIAdapter
                ? ['type' => 'RUN_ERROR', 'message' => 'The run failed.', 'code' => '503']
                : ['type' => 'error', 'errorText' => 'The run failed.'],
            $events[array_key_last($events)],
        );
    }

    /**
     * @return iterable<string, array{StreamAdapterInterface, bool, list<string>, bool}>
     */
    public static function failed_streams(): iterable
    {
        foreach ([false, true] as $push) {
            $mode = $push ? 'push' : 'pull';
            yield "AG-UI before output ($mode)" => [new AGUIAdapter('thread_test'), false, ['RUN_STARTED', 'RUN_ERROR'], $push];
            yield "AG-UI after output ($mode)" => [new AGUIAdapter('thread_test'), true, [
                'RUN_STARTED', 'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END', 'RUN_ERROR',
            ], $push];
            yield "Vercel before output ($mode)" => [new VercelAIAdapter(), false, ['error'], $push];
            yield "Vercel after output ($mode)" => [new VercelAIAdapter(), true, ['start', 'text-start', 'text-delta', 'text-end', 'error'], $push];
        }
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
            ->setStreamAdapter(fn (): StreamAdapterInterface => $adapter)
            ->setChannel(fn (): StreamingChannelInterface => $channel);

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
            ->setStreamAdapter(fn (): StreamAdapterInterface => $this->adapterFailingWith($error))
            ->subscribe(WorkflowError::class, function (WorkflowError $event) use (&$observed): void {
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
        $this->assertSame(WorkflowStatus::Failed, $workflow->inspect()->status);

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
            ->setBranchRunner(new AsyncBranchRunner())
            ->addNodes([new ImageFirstForkNode(), new StreamingImageProcessNode(), new TextProcessNode(), new MergeNode()])
            ->setStreamAdapter(fn (): StreamAdapterInterface => $this->adapterFailingWith($error));

        $caught = null;
        try {
            $workflow->run();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        // The async executor drains the sibling branch before it surfaces the
        // injected failure, so the run still ends up failed, not running.
        $this->assertSame($error, $caught);
        $this->assertSame(WorkflowStatus::Failed, $workflow->inspect()->status);
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
