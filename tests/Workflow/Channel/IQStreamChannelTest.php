<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use Closure;
use InvalidArgumentException;
use IQStream\Answer;
use IQStream\Credentials;
use IQStream\ErrorReporter;
use IQStream\Exceptions\IQError;
use IQStream\IQClient;
use IQStream\PublisherOptions;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Workflow\Channel\Stub\RecordingIQTransport;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Streaming\Channel\IQStreamChannel;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_fill;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function base64_decode;
use function count;
use function implode;
use function json_decode;
use function ksort;
use function str_repeat;
use function str_split;
use function strlen;
use function strtr;

class IQStreamChannelTest extends TestCase
{
    protected const STREAM = 'thread-42-run-0123456789';

    /**
     * A client of the recording server, with no pause before a close and no time spent retrying.
     */
    protected function client(RecordingIQTransport $server, int $maxFrameBytes = 65_536): IQClient
    {
        $quiet = new class () implements ErrorReporter {
            public function report(IQError $error): void
            {
            }
        };

        return new IQClient(
            'http://iq.example',
            new Credentials('neuron-app', 'test-app-secret-0123456789abcdef0123'),
            options: new PublisherOptions(minOpen: 0, maxFrameBytes: $maxFrameBytes, errorReporter: $quiet, retryBudget: 0),
            transport: $server,
        );
    }

    protected function state(): WorkflowState
    {
        $state = new WorkflowState();
        $state->setExecutionMetadata('wf-1', 'run-1', 1);

        return $state;
    }

    protected function failure(Closure $delivery): RuntimeException
    {
        try {
            $delivery();
        } catch (RuntimeException $e) {
            return $e;
        }

        $this->fail('The channel reported no failure.');
    }

    /**
     * The envelopes the frames of the stream carry.
     *
     * @return list<array<string, mixed>>
     */
    protected function envelopes(RecordingIQTransport $server): array
    {
        return array_map(
            static fn (string $line): array => json_decode(json_decode($line, true)['data'], true),
            $server->frames,
        );
    }

    public function test_a_segment_is_one_request_with_a_frame_per_event_and_the_close_line_last(): void
    {
        $server = new RecordingIQTransport();
        $channel = new IQStreamChannel($this->client($server), self::STREAM);

        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));
        // A line break in the payload stays inside its frame: NDJSON ends a frame at the first one.
        $channel->send(new ProtocolEvent('text-delta', ['delta' => " \"world\"/é\r\n"]));
        $channel->completed($this->state(), 'wf-1');

        $this->assertCount(1, $server->requests);
        $lines = $server->lines();
        $this->assertSame('{"close":"end"}', $lines[3]);
        // No event name: every frame reaches the subscriber's message listener.
        foreach (array_slice($lines, 0, 3) as $line) {
            $this->assertSame(['data'], array_keys(json_decode($line, true)));
        }
        $envelopes = $this->envelopes($server);
        $this->assertSame(['text-delta', 'text-delta', 'stream.completed'], array_column($envelopes, 'type'));
        $this->assertSame([0, 1, 2], array_column($envelopes, 'sequence'));
        $this->assertSame(" \"world\"/é\r\n", $envelopes[1]['data']['delta']);
        $this->assertSame(['workflowId' => 'wf-1'], $envelopes[2]['data']);
    }

    public function test_the_request_opens_on_the_first_event(): void
    {
        $server = new RecordingIQTransport();

        $channel = new IQStreamChannel($this->client($server), self::STREAM);
        $this->assertSame([], $server->requests);

        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));
        $this->assertCount(1, $server->requests);
    }

    /**
     * @return iterable<string, array{Closure(IQStreamChannel, WorkflowState): void, string, string}>
     */
    public static function segmentEndings(): iterable
    {
        yield 'completed' => [static fn (IQStreamChannel $channel, WorkflowState $state) => $channel->completed($state, 'wf-1'), 'stream.completed', 'end'];
        yield 'interrupted' => [static fn (IQStreamChannel $channel, WorkflowState $state) => $channel->interrupted($state), 'stream.interrupted', 'end'];
        yield 'failed' => [static fn (IQStreamChannel $channel) => $channel->failed(new RuntimeException('Boom'), 'wf-1'), 'stream.failed', 'fail'];
    }

    /**
     * @param Closure(IQStreamChannel, WorkflowState): void $end
     */
    #[DataProvider('segmentEndings')]
    public function test_the_terminal_event_closes_the_stream_with_its_outcome(Closure $end, string $terminal, string $outcome): void
    {
        $server = new RecordingIQTransport();
        $channel = new IQStreamChannel($this->client($server), self::STREAM);

        $end($channel, $this->state());

        $this->assertSame([$terminal], array_column($this->envelopes($server), 'type'));
        $this->assertSame($outcome, $server->closedWith);
    }

    public function test_a_refusal_stops_the_stream_with_the_server_answer(): void
    {
        $server = new RecordingIQTransport(new Answer(403, ['error' => 'forbidden']));
        $channel = new IQStreamChannel($this->client($server), self::STREAM);

        $failure = $this->failure(fn () => $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello'])));

        $this->assertSame('IQ stream ' . self::STREAM . ': the server refused the request (HTTP 403, forbidden)', $failure->getMessage());
        // Later events are dropped, and the end of the segment is reported too.
        $channel->send(new ProtocolEvent('text-delta', ['delta' => ' world']));
        $this->failure(fn () => $channel->completed($this->state(), 'wf-1'));
        $this->assertSame([], $server->frames);
    }

    public function test_after_the_server_lets_go_of_a_silent_request_the_next_event_continues_the_stream(): void
    {
        // As while a tool runs: the server answers the first request with 408 after its first frame.
        $server = new RecordingIQTransport(1);
        $channel = new IQStreamChannel($this->client($server), self::STREAM);

        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => ' world']));
        $channel->completed($this->state(), 'wf-1');

        $this->assertSame([null, 1], array_column($server->requests, 'continues'));
        $this->assertSame([0, 1, 2], array_column($this->envelopes($server), 'sequence'));
        $this->assertSame('end', $server->closedWith);
    }

    public function test_frames_stay_within_the_frame_limit_and_a_large_event_is_fragmented(): void
    {
        $server = new RecordingIQTransport();
        $channel = new IQStreamChannel($this->client($server, maxFrameBytes: 300), self::STREAM);
        $payload = ['output' => str_repeat('a"/é', 300)];

        $channel->send(new ProtocolEvent('tool-output-available', $payload));
        $channel->completed($this->state(), 'wf-1');

        foreach ($server->frames as $line) {
            $this->assertLessThanOrEqual(300, strlen($line));
        }
        $fragments = array_slice($this->envelopes($server), 0, -1);
        $this->assertGreaterThan(1, count($fragments));
        $parts = [];
        foreach ($fragments as $fragment) {
            $this->assertSame('stream.fragment', $fragment['type']);
            $this->assertSame('tool-output-available', $fragment['data']['event']);
            $parts[$fragment['data']['index']] = $fragment['data']['part'];
        }
        ksort($parts);
        $this->assertSame($payload, json_decode(base64_decode(strtr(implode('', $parts), '-_', '+/')), true));
    }

    public function test_a_server_that_cannot_be_reached_stops_the_stream(): void
    {
        // The publish request, the question that follows it, and the close that tells subscribers.
        $server = new RecordingIQTransport(...array_fill(0, 3, Answer::none('cannot connect to http://iq.example: Connection refused')));
        $channel = new IQStreamChannel($this->client($server), self::STREAM);

        $failure = $this->failure(fn () => $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello'])));

        $this->assertSame(
            'IQ stream ' . self::STREAM . ': gave up retrying (unreachable: cannot connect to http://iq.example: Connection refused)',
            $failure->getMessage(),
        );
    }

    public function test_the_stream_of_a_ticket_is_taken_as_it_is(): void
    {
        $server = new RecordingIQTransport();
        $client = $this->client($server);

        (new IQStreamChannel($client, $client->ticket()->streamId))->completed($this->state(), 'wf-1');

        $this->assertSame('end', $server->closedWith);
    }

    public function test_a_closed_stream_takes_no_second_segment(): void
    {
        $client = $this->client(new RecordingIQTransport());
        (new IQStreamChannel($client, self::STREAM))->completed($this->state(), 'wf-1');

        $failure = $this->failure(fn () => (new IQStreamChannel($client, self::STREAM))->completed($this->state(), 'wf-1'));

        $this->assertSame('IQ stream ' . self::STREAM . ': the stream is closed (HTTP 409, stream_closed)', $failure->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStreams(): iterable
    {
        yield 'too short' => [str_repeat('a', 15)];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'with a dot' => ['thread.42.run.0123456789'];
        // It would end the request line early, and the rest would be read as headers.
        yield 'ending with a line break' => [self::STREAM . "\n"];
    }

    #[DataProvider('invalidStreams')]
    public function test_the_stream_id_is_validated(string $stream): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IQStreamChannel($this->client(new RecordingIQTransport()), $stream);
    }

    public function test_streams_an_agent_run_as_the_adapter_events_followed_by_the_completion(): void
    {
        $server = new RecordingIQTransport();
        $channel = new IQStreamChannel($this->client($server), self::STREAM);
        $agent = Agent::make()->setThreadId('thread_1')
            ->setStreamAdapter(fn (): VercelAIAdapter => new VercelAIAdapter())
            ->setChannel(fn (): StreamingChannelInterface => $channel);
        $response = 'Hello world from IQ';
        $agent->setAiProvider((new FakeAIProvider(new AssistantMessage($response)))->setStreamChunkSize(5));

        $state = $agent->run(ExecutionRequest::start(new AgentStartEvent([new UserMessage('Hi')], new AgentRunOptions(stream: true))));

        $envelopes = $this->envelopes($server);
        $this->assertSame($response, $state->getMessage()->getContent());
        $this->assertSame(
            array_merge(['start', 'text-start'], array_fill(0, count(str_split($response, 5)), 'text-delta'), ['text-end', 'finish', 'stream.completed']),
            array_column($envelopes, 'type'),
        );
        $this->assertSame($response, implode('', array_column(array_column($envelopes, 'data'), 'delta')));
        $this->assertSame('end', $server->closedWith);
        $this->assertCount(1, $server->requests);
    }
}
