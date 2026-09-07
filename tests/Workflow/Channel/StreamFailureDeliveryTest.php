<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use Error;
use NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter;
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Workflow\Channel\Stub\FailingStreamNode;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_column;
use function array_key_last;
use function array_map;
use function array_pop;
use function json_decode;
use function substr;

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
        $this->assertSame($pulled, $channel->lines);
        $this->assertSame([], $channel->sent);
        $this->assertCount(1, $channel->failures);
        $this->assertSame($error, $channel->failures[0]['exception']);
        $this->assertSame([], $channel->completions);
        $this->assertSame([], $channel->suspendedStates);

        if ($adapter instanceof VercelAIAdapter) {
            $this->assertSame("data: [DONE]\n\n", array_pop($pulled));
        }
        $events = array_map(static fn (string $line): array => json_decode(substr($line, 6, -2), true), $pulled);
        $this->assertSame($expectedTypes, array_column($events, 'type'));
        $this->assertSame(
            $adapter instanceof AGUIAdapter
                ? ['type' => 'RUN_ERROR', 'message' => $error->getMessage(), 'code' => '503']
                : ['type' => 'error', 'errorText' => $error->getMessage()],
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
        yield 'Vercel after output' => [new VercelAIAdapter(), true, ['start', 'text-delta', 'error']];
    }

    public function test_custom_adapter_receives_original_throwable_during_run(): void
    {
        $error = new Error('Node failed');
        $adapter = $this->createMock(StreamAdapterInterface::class);
        $adapter->expects($this->once())->method('start')->willReturn([]);
        $adapter->expects($this->once())->method('error')->with($this->identicalTo($error))->willReturn(['failed']);
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
        $this->assertSame(['failed'], $channel->lines);
        $this->assertCount(1, $channel->failures);
        $this->assertSame($error, $channel->failures[0]['exception']);
        $this->assertSame([], $channel->completions);
    }
}
