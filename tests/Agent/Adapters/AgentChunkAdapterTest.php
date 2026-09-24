<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Adapters;

use NeuronAI\Agent\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Agent\Adapters\Events\StepFinishedStreamEvent;
use NeuronAI\Agent\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Agent\Adapters\Events\StreamEventInterface;
use NeuronAI\Agent\Adapters\AgentChunkAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\AudioChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ImageChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\StreamAdapterException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Agent\Adapters\Stub\DetailedIndexingProgress;
use NeuronAI\Tests\Agent\Adapters\Stub\IndexingProgress;
use NeuronAI\Tests\Agent\Adapters\Stub\SuppressedProgress;
use NeuronAI\Tests\Agent\Adapters\Stub\UnsupportedStreamEvent;
use NeuronAI\Tests\Agent\Stub\GetWeatherTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

use function array_column;
use function array_filter;
use function array_unique;
use function array_values;
use function implode;
use function json_decode;
use function json_encode;

class AgentChunkAdapterTest extends TestCase
{
    #[DataProvider('chunks')]
    public function test_each_chunk_becomes_one_event_named_after_its_kind(StreamChunk $chunk, string $type): void
    {
        $events = $this->decode((new AgentChunkAdapter())->transform($chunk));

        $this->assertSame([['type' => $type, ...json_decode(json_encode($chunk->toArray()), true)]], $events);
    }

    /**
     * @return array<string, array{StreamChunk, string}>
     */
    public static function chunks(): array
    {
        $call = ToolCall::make('get_weather', 'call_1', ['location' => 'Rome']);

        return [
            'text' => [new TextChunk('msg_1', 'Hello'), 'text'],
            'reasoning' => [new ReasoningChunk('msg_1', 'Thinking'), 'reasoning'],
            'image' => [new ImageChunk('msg_1', 'base64-image'), 'image'],
            'audio' => [new AudioChunk('msg_1', 'base64-audio'), 'audio'],
            'tool argument' => [new ToolArgumentChunk('msg_1', 'get_weather', '{"loc', 'call_1'), 'tool-argument'],
            'tool call' => [new ToolCallChunk('msg_1', $call), 'tool-call'],
            'tool result' => [new ToolResultChunk((clone $call)->setResult('sunny')), 'tool-result'],
        ];
    }

    public function test_tool_chunks_carry_the_call_under_the_same_key(): void
    {
        $call = ToolCall::make('get_weather', 'call_1', ['location' => 'Rome']);
        $adapter = new AgentChunkAdapter();

        $requested = $this->decode($adapter->transform(new ToolCallChunk('msg_1', $call)))[0];
        $settled = $this->decode($adapter->transform(new ToolResultChunk((clone $call)->setResult('sunny'))))[0];

        $this->assertSame('call_1', $requested['tool']['callId']);
        $this->assertSame(['location' => 'Rome'], $requested['tool']['inputs']);
        $this->assertSame('call_1', $settled['tool']['callId']);
        $this->assertSame('sunny', $settled['tool']['result']);
    }

    public function test_portable_events_keep_a_stable_shape(): void
    {
        $adapter = new AgentChunkAdapter();

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
            ['type' => 'step-started', 'name' => 'indexing', 'metadata' => ['queue' => 'high']],
            ['type' => 'activity', 'id' => 'job-1', 'activityType' => 'indexing', 'data' => ['processed' => 10, 'total' => 100]],
            ['type' => 'custom', 'name' => 'notice', 'value' => ['message' => 'Working']],
            ['type' => 'step-finished', 'name' => 'indexing', 'metadata' => []],
        ], $events);
    }

    public function test_empty_maps_stay_json_objects(): void
    {
        $adapter = new AgentChunkAdapter();

        $this->assertSame(
            '{"type":"step-finished","name":"indexing","metadata":{}}',
            json_encode([...$adapter->transform(new StepFinishedStreamEvent('indexing'))][0]),
        );
        $this->assertSame(
            '{"type":"activity","id":"job-1","activityType":"indexing","data":{}}',
            json_encode([...$adapter->transform(new ActivityStreamEvent('job-1', 'indexing', []))][0]),
        );
    }

    public function test_interrupt_nests_the_request_so_its_type_keeps_the_event_discriminator(): void
    {
        $request = (new ApprovalRequest('Location access needs consent', [
            new Action('call_1', 'get_weather', inputs: ['location' => 'Rome']),
        ]))->withId(1);

        $events = $this->decode((new AgentChunkAdapter())->interrupt($request));

        $this->assertCount(1, $events);
        $this->assertSame('interrupt', $events[0]['type']);
        $this->assertSame(json_decode(json_encode($request), true), $events[0]['request']);
        $this->assertSame('call_1', $events[0]['request']['actions'][0]['id']);
    }

    public function test_error_exposes_only_the_neutral_text(): void
    {
        $events = $this->decode((new AgentChunkAdapter())->error(new RuntimeException('Provider failed at https://internal')));

        $this->assertSame([['type' => 'error', 'message' => 'The run failed.']], $events);
    }

    public function test_error_message_hook_decides_the_wire_text(): void
    {
        $adapter = new class () extends AgentChunkAdapter {
            protected function errorMessage(Throwable $error): string
            {
                return 'Visible: ' . $error->getMessage();
            }
        };

        $events = $this->decode($adapter->error(new RuntimeException('provider down')));

        $this->assertSame('Visible: provider down', $events[0]['message']);
    }

    public function test_start_and_end_frame_nothing(): void
    {
        $adapter = new AgentChunkAdapter();

        $this->assertSame([], $adapter->start());
        $this->assertSame([], $adapter->end());
    }

    public function test_unknown_objects_are_ignored(): void
    {
        $adapter = new AgentChunkAdapter();
        $unknownChunk = new class () extends StreamChunk {
            public function toArray(): array
            {
                return [];
            }
        };

        $this->assertSame([], $adapter->transform(new stdClass()));
        $this->assertSame([], $adapter->transform($unknownChunk));
    }

    public function test_exact_class_mapping_converts_domain_events(): void
    {
        $adapter = (new AgentChunkAdapter())->mapEvent(
            IndexingProgress::class,
            static fn (IndexingProgress $event): ActivityStreamEvent => new ActivityStreamEvent(
                id: $event->jobId,
                type: 'indexing',
                data: ['processed' => $event->processed],
            ),
        );

        $mapped = $this->decode($adapter->transform(new IndexingProgress('job-1', 5)));

        $this->assertSame('activity', $mapped[0]['type']);
        $this->assertSame('job-1', $mapped[0]['id']);
        $this->assertSame([], $adapter->transform(new DetailedIndexingProgress('job-2', 6)), 'Mappings must not match subclasses implicitly.');
    }

    public function test_mapping_can_explicitly_suppress_an_event(): void
    {
        $adapter = (new AgentChunkAdapter())->mapEvent(
            SuppressedProgress::class,
            static fn (SuppressedProgress $event): ?StreamEventInterface => null,
        );

        $this->assertSame([], $adapter->transform(new SuppressedProgress()));
    }

    public function test_unsupported_portable_event_fails_loudly(): void
    {
        $this->expectException(StreamAdapterException::class);
        $this->expectExceptionMessage(UnsupportedStreamEvent::class);

        (new AgentChunkAdapter())->transform(new UnsupportedStreamEvent());
    }

    public function test_channel_receives_the_native_chunks_of_a_streamed_run(): void
    {
        $channel = new FakeChannel();
        $agent = Agent::make()
            ->setStreamAdapter(new AgentChunkAdapter())
            ->setChannel($channel)
            ->setAiProvider((new FakeAIProvider(
                new ToolCallMessage(null, [ToolCall::make('get_weather', 'call_1', ['location' => 'Rome'])]),
                new AssistantMessage('It is sunny in Rome.'),
            ))->setStreamChunkSize(5))
            ->addTool(GetWeatherTool::make());

        $agent->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(new \NeuronAI\Agent\Events\AgentStartEvent([new UserMessage('Weather in Rome?')], new \NeuronAI\Agent\AgentRunOptions(stream: true))));

        $sent = $this->decode($channel->getSent());

        $this->assertSame(
            ['tool-argument', 'tool-call', 'tool-result', 'text'],
            array_values(array_unique(array_column($sent, 'type'))),
        );
        $this->assertSame('It is sunny in Rome.', implode('', array_column($this->ofType($sent, 'text'), 'content')));
        $this->assertSame('Weather for Rome: sunny', $this->ofType($sent, 'tool-result')[0]['tool']['result']);
        $this->assertCount(1, $channel->getCompletions());
    }

    public function test_channel_learns_what_a_suspended_run_waits_for(): void
    {
        $channel = new FakeChannel();
        $agent = Agent::make(workflowId: 'thread-1')
            ->setPersistence(new InMemoryPersistence())
            ->setStreamAdapter(new AgentChunkAdapter())
            ->setChannel($channel)
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [ToolCall::make('get_weather', 'call_1', ['location' => 'Rome'])]),
            ))
            ->addTool(GetWeatherTool::make()->requireApproval());

        $state = $agent->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(new \NeuronAI\Agent\Events\AgentStartEvent([new UserMessage('Weather in Rome?')], new \NeuronAI\Agent\AgentRunOptions(stream: true))));

        $interrupts = $this->ofType($this->decode($channel->getSent()), 'interrupt');

        $this->assertTrue($state->isInterrupted());
        $this->assertCount(1, $interrupts);
        $this->assertSame('call_1', $interrupts[0]['request']['actions'][0]['id']);
        $this->assertSame(['location' => 'Rome'], $interrupts[0]['request']['actions'][0]['inputs']);
        $this->assertCount(1, $channel->getSuspensions());
    }

    /**
     * @param iterable<ProtocolEvent> $events
     * @return list<array<string, mixed>>
     */
    protected function decode(iterable $events): array
    {
        $decoded = [];

        foreach ($events as $event) {
            $decoded[] = json_decode(json_encode($event), true);
        }

        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    protected function ofType(array $events, string $type): array
    {
        return array_values(array_filter(
            $events,
            static fn (array $event): bool => $event['type'] === $type,
        ));
    }
}
