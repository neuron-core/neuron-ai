<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages\Stream\Adapters;

use NeuronAI\Tests\Chat\Messages\Stream\Adapters\Stub\DetailedIndexingProgress;
use NeuronAI\Tests\Chat\Messages\Stream\Adapters\Stub\IndexingProgress;
use NeuronAI\Tests\Chat\Messages\Stream\Adapters\Stub\SuppressedProgress;
use NeuronAI\Tests\Chat\Messages\Stream\Adapters\Stub\UnsupportedStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter;
use NeuronAI\Chat\Messages\Stream\Adapters\CustomizableStreamAdapterInterface;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\CustomStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StepFinishedStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StreamEventInterface;
use NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\StreamAdapterException;
use PHPUnit\Framework\TestCase;

use function array_column;
use function iterator_to_array;
use function json_decode;
use function substr;

class StreamEventAdapterTest extends TestCase
{
    public function test_agui_encodes_all_portable_events(): void
    {
        $adapter = new AGUIAdapter('thread-1');

        $events = [
            ...$this->decode($adapter->transform(new StepStartedStreamEvent('indexing', ['queue' => 'high']))),
            ...$this->decode($adapter->transform(new ActivityStreamEvent(
                id: 'job-1',
                type: 'indexing',
                data: ['processed' => 10, 'total' => 100],
            ))),
            ...$this->decode($adapter->transform(new CustomStreamEvent('notice', ['message' => 'Working']))),
            ...$this->decode($adapter->transform(new StepFinishedStreamEvent('indexing'))),
        ];

        $this->assertSame([
            'STEP_STARTED',
            'ACTIVITY_SNAPSHOT',
            'CUSTOM',
            'STEP_FINISHED',
        ], array_column($events, 'type'));
        $this->assertSame('indexing', $events[0]['stepName']);
        $this->assertSame(['queue' => 'high'], $events[0]['metadata']);
        $this->assertSame('job-1', $events[1]['messageId']);
        $this->assertSame('indexing', $events[1]['activityType']);
        $this->assertSame(['processed' => 10, 'total' => 100], $events[1]['content']);
        $this->assertTrue($events[1]['replace']);
        $this->assertSame('notice', $events[2]['name']);
        $this->assertSame(['message' => 'Working'], $events[2]['value']);
    }

    public function test_vercel_encodes_portable_events_as_transient_data(): void
    {
        $adapter = new VercelAIAdapter();

        $events = [
            ...$this->decode($adapter->transform(new StepStartedStreamEvent('indexing'))),
            ...$this->decode($adapter->transform(new ActivityStreamEvent(
                id: 'job-1',
                type: 'indexing',
                data: ['processed' => 10],
            ))),
            ...$this->decode($adapter->transform(new CustomStreamEvent('notice', ['message' => 'Working']))),
            ...$this->decode($adapter->transform(new StepFinishedStreamEvent('indexing', ['items' => 10]))),
        ];

        $this->assertSame([
            'data-workflow-step',
            'data-workflow-activity',
            'data-notice',
            'data-workflow-step',
        ], array_column($events, 'type'));
        $this->assertSame('started', $events[0]['data']['status']);
        $this->assertSame([
            'id' => 'job-1',
            'type' => 'indexing',
            'data' => ['processed' => 10],
        ], $events[1]['data']);
        $this->assertSame(['message' => 'Working'], $events[2]['data']);
        $this->assertSame('finished', $events[3]['data']['status']);
        $this->assertSame(['items' => 10], $events[3]['data']['metadata']);

        foreach ($events as $event) {
            $this->assertTrue($event['transient']);
        }
    }

    public function test_vercel_portable_event_can_precede_message_start(): void
    {
        $adapter = new VercelAIAdapter();

        $beforeMessage = $this->decode($adapter->transform(
            new CustomStreamEvent('status', ['message' => 'Preparing'])
        ));
        $message = $this->decode($adapter->transform(new TextChunk('message-1', 'Hello')));

        $this->assertSame(['data-status'], array_column($beforeMessage, 'type'));
        $this->assertSame(['start', 'text-delta'], array_column($message, 'type'));
        $this->assertSame('message-1', $message[0]['messageId']);
    }

    public function test_exact_class_mapping_converts_domain_events(): void
    {
        $adapter = (new AGUIAdapter('thread-1'))->mapEvent(
            IndexingProgress::class,
            static fn (IndexingProgress $event): ActivityStreamEvent => new ActivityStreamEvent(
                id: $event->jobId,
                type: 'indexing',
                data: ['processed' => $event->processed],
            ),
        );

        $this->assertInstanceOf(CustomizableStreamAdapterInterface::class, $adapter);

        $mapped = $this->decode($adapter->transform(new IndexingProgress('job-1', 5)));
        $subclass = iterator_to_array($adapter->transform(new DetailedIndexingProgress('job-2', 6)));

        $this->assertSame('ACTIVITY_SNAPSHOT', $mapped[0]['type']);
        $this->assertSame('job-1', $mapped[0]['messageId']);
        $this->assertSame([], $subclass, 'Mappings must not match subclasses implicitly.');
    }

    public function test_mapping_can_explicitly_suppress_an_event(): void
    {
        $adapter = (new VercelAIAdapter())->mapEvent(
            SuppressedProgress::class,
            static fn (SuppressedProgress $event): ?StreamEventInterface => null,
        );

        $this->assertSame([], iterator_to_array($adapter->transform(new SuppressedProgress())));
    }

    public function test_unsupported_portable_event_fails_loudly(): void
    {
        $this->expectException(StreamAdapterException::class);
        $this->expectExceptionMessage(UnsupportedStreamEvent::class);

        iterator_to_array((new VercelAIAdapter())->transform(new UnsupportedStreamEvent()));
    }

    /**
     * @param iterable<string> $frames
     * @return list<array<string, mixed>>
     */
    protected function decode(iterable $frames): array
    {
        $events = [];

        foreach ($frames as $frame) {
            $decoded = json_decode(substr($frame, 6, -2), true);
            $this->assertIsArray($decoded);
            $events[] = $decoded;
        }

        return $events;
    }
}
