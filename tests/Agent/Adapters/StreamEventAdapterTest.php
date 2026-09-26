<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Adapters;

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Adapters\AgentChunkAdapter;
use NeuronAI\Agent\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Agent\Adapters\Events\StepFinishedStreamEvent;
use NeuronAI\Agent\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Agent\Adapters\Events\StreamEventInterface;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\StreamAdapterException;
use NeuronAI\Tests\Agent\Adapters\Stub\DetailedIndexingProgress;
use NeuronAI\Tests\Agent\Adapters\Stub\IndexingProgress;
use NeuronAI\Tests\Agent\Adapters\Stub\SuppressedProgress;
use NeuronAI\Tests\Agent\Adapters\Stub\UnsupportedStreamEvent;
use NeuronAI\Agent\Adapters\CustomizableStreamAdapterInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function preg_quote;

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
        $this->assertSame(['start', 'text-start', 'text-delta'], array_column($message, 'type'));
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

    /** @return iterable<string, array{CustomizableStreamAdapterInterface}> */
    public static function adapters(): iterable
    {
        yield 'AG-UI' => [new AGUIAdapter('thread-1')];
        yield 'Vercel' => [new VercelAIAdapter()];
        yield 'native' => [new AgentChunkAdapter()];
    }

    #[DataProvider('adapters')]
    public function test_a_mapper_returning_a_protocol_payload_fails_loudly(CustomizableStreamAdapterInterface $adapter): void
    {
        $adapter->mapEvent(IndexingProgress::class, $this->protocolPayloadMapper());

        $this->expectException(StreamAdapterException::class);
        $this->expectExceptionMessage(
            'The stream event mapper for ' . IndexingProgress::class . ' must return ' . StreamEventInterface::class . ' or null.'
        );

        iterator_to_array($adapter->transform(new IndexingProgress('job-1', 5)), false);
    }

    /** @return iterable<string, array{CustomizableStreamAdapterInterface, array<string, mixed>}> */
    public static function customTextFrames(): iterable
    {
        yield 'AG-UI' => [new AGUIAdapter('thread-1'), ['type' => 'CUSTOM', 'name' => 'text', 'value' => 'Hello']];
        yield 'Vercel' => [new VercelAIAdapter(), ['type' => 'data-text', 'data' => 'Hello', 'transient' => true]];
        yield 'native' => [new AgentChunkAdapter(), ['type' => 'custom', 'name' => 'text', 'value' => 'Hello']];
    }

    /**
     * @param array<string, mixed> $frame
     */
    #[DataProvider('customTextFrames')]
    public function test_a_mapping_takes_precedence_over_native_chunk_conversion(CustomizableStreamAdapterInterface $adapter, array $frame): void
    {
        $adapter->mapEvent(TextChunk::class, static fn (TextChunk $chunk): CustomStreamEvent => new CustomStreamEvent('text', $chunk->content));

        $this->assertSame([$frame], $this->decode($adapter->transform(new TextChunk('msg_1', 'Hello'))));
    }

    #[DataProvider('adapters')]
    public function test_a_suppressed_chunk_never_falls_through_to_native_conversion(CustomizableStreamAdapterInterface $adapter): void
    {
        $adapter->mapEvent(TextChunk::class, static fn (TextChunk $chunk): ?StreamEventInterface => null);

        $this->assertSame([], $this->decode($adapter->transform(new TextChunk('msg_1', 'Hello'))));
    }

    /**
     * @param array<string, mixed> $frame
     */
    #[DataProvider('customTextFrames')]
    public function test_a_portable_event_is_encoded_directly_even_when_mapped(CustomizableStreamAdapterInterface $adapter, array $frame): void
    {
        $adapter->mapEvent(CustomStreamEvent::class, static fn (CustomStreamEvent $event): ?StreamEventInterface => null);

        $this->assertSame([$frame], $this->decode($adapter->transform(new CustomStreamEvent('text', 'Hello'))));
    }

    #[DataProvider('adapters')]
    public function test_a_later_mapping_replaces_the_earlier_one(CustomizableStreamAdapterInterface $adapter): void
    {
        $returned = $adapter
            ->mapEvent(IndexingProgress::class, static fn (IndexingProgress $event): ?StreamEventInterface => null)
            ->mapEvent(IndexingProgress::class, static fn (IndexingProgress $event): StepStartedStreamEvent => new StepStartedStreamEvent($event->jobId));

        $this->assertSame($adapter, $returned);
        $this->assertCount(1, $this->decode($adapter->transform(new IndexingProgress('job-1', 5))));
    }

    #[DataProvider('adapters')]
    public function test_unsupported_portable_events_name_the_class(CustomizableStreamAdapterInterface $adapter): void
    {
        $this->expectException(StreamAdapterException::class);
        $this->expectExceptionMessageMatches('/cannot encode stream event ' . preg_quote(UnsupportedStreamEvent::class, '/') . '\.$/');

        iterator_to_array($adapter->transform(new UnsupportedStreamEvent()), false);
    }

    /** @return iterable<string, array{callable(): StreamEventInterface, string}> */
    public static function invalidPortableEvents(): iterable
    {
        yield 'blank step started' => [static fn (): StreamEventInterface => new StepStartedStreamEvent(" \t\n"), 'A stream step name cannot be empty.'];
        yield 'empty step finished' => [static fn (): StreamEventInterface => new StepFinishedStreamEvent(''), 'A stream step name cannot be empty.'];
        yield 'blank activity ID' => [static fn (): StreamEventInterface => new ActivityStreamEvent(' ', 'indexing', []), 'A stream activity ID cannot be empty.'];
        yield 'empty activity type' => [static fn (): StreamEventInterface => new ActivityStreamEvent('job-1', '', []), 'A stream activity type cannot be empty.'];
        yield 'blank activity type' => [static fn (): StreamEventInterface => new ActivityStreamEvent('job-1', "\t ", []), 'A stream activity type cannot be empty.'];
        yield 'blank custom name' => [static fn (): StreamEventInterface => new CustomStreamEvent('  ', 'value'), 'A custom stream event name cannot be empty.'];
    }

    /**
     * @param callable(): StreamEventInterface $create
     */
    #[DataProvider('invalidPortableEvents')]
    public function test_portable_events_require_a_name(callable $create, string $message): void
    {
        $this->expectException(StreamAdapterException::class);
        $this->expectExceptionMessage($message);

        $create();
    }

    public function test_agui_activity_snapshots_replace_each_other_in_the_messages_snapshot(): void
    {
        $adapter = new AGUIAdapter('thread-1', 'run-1');
        iterator_to_array($adapter->transform(new ActivityStreamEvent('job-1', 'indexing', ['processed' => 1])), false);
        iterator_to_array($adapter->transform(new ActivityStreamEvent('job-1', 'indexing', ['processed' => 2])), false);

        $frames = iterator_to_array($adapter->interrupt((new WaitForEventRequest('done'))->withId(1)), false);

        $this->assertSame(
            '[{"id":"job-1","role":"activity","activityType":"indexing","content":{"processed":2}}]',
            json_encode($frames[1]->data['messages']),
        );
    }

    public function test_agui_empty_activity_content_stays_a_json_object(): void
    {
        $adapter = new AGUIAdapter('thread-1', 'run-1');
        $frames = iterator_to_array($adapter->transform(new ActivityStreamEvent('job-1', 'indexing', [])), false);
        $snapshot = iterator_to_array($adapter->interrupt((new WaitForEventRequest('done'))->withId(1)), false);

        $this->assertSame(
            '{"type":"ACTIVITY_SNAPSHOT","messageId":"job-1","activityType":"indexing","content":{},"replace":true}',
            json_encode($frames[0]),
        );
        $this->assertSame(
            '[{"id":"job-1","role":"activity","activityType":"indexing","content":{}}]',
            json_encode($snapshot[1]->data['messages']),
        );
    }

    public function test_step_metadata_is_omitted_when_empty(): void
    {
        $agui = $this->decode((new AGUIAdapter('thread-1'))->transform(new StepStartedStreamEvent('indexing')));
        $vercel = $this->decode((new VercelAIAdapter())->transform(new StepStartedStreamEvent('indexing')));

        $this->assertSame([['type' => 'STEP_STARTED', 'stepName' => 'indexing']], $agui);
        $this->assertSame([['type' => 'data-workflow-step', 'data' => ['name' => 'indexing', 'status' => 'started'], 'transient' => true]], $vercel);
    }

    /**
     * A mapper breaking the contract, which the adapters must catch at runtime.
     */
    protected function protocolPayloadMapper(): callable
    {
        return static fn (IndexingProgress $event): array => ['type' => 'CUSTOM', 'name' => $event->jobId];
    }

    /**
     * @param iterable<ProtocolEvent> $frames
     * @return list<array<string, mixed>>
     */
    protected function decode(iterable $frames): array
    {
        $events = [];

        foreach ($frames as $frame) {
            $decoded = json_decode(json_encode($frame), true);
            $this->assertIsArray($decoded);
            $events[] = $decoded;
        }

        return $events;
    }
}
