<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use Closure;
use InvalidArgumentException;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Workflow\Channel\Stub\SocketPairIQStreamChannel;
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
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_values;
use function base64_decode;
use function count;
use function explode;
use function fclose;
use function implode;
use function json_decode;
use function ksort;
use function str_repeat;
use function str_split;
use function strlen;
use function strtr;
use function stream_socket_get_name;
use function stream_socket_server;

class IQStreamChannelTest extends TestCase
{
    protected const STREAM = 'thread-42-run-0123456789';

    /**
     * A channel with what the other end of its connection does to each request, in order.
     *
     * @param Closure(string): ?string ...$peers
     */
    protected function channel(Closure ...$peers): SocketPairIQStreamChannel
    {
        return new SocketPairIQStreamChannel($peers, 'http://iq.example', self::STREAM, 'pub-token');
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
     * The envelopes the data lines carry, without the close line.
     *
     * @return list<array<string, mixed>>
     */
    protected function envelopes(string $request): array
    {
        $frames = array_map(static fn (string $line): array => json_decode($line, true), SocketPairIQStreamChannel::lines($request));

        return array_values(array_map(
            static fn (array $frame): array => json_decode($frame['data'], true),
            array_filter($frames, static fn (array $frame): bool => isset($frame['data'])),
        ));
    }

    public function test_a_segment_is_one_chunked_request_with_a_line_per_event_and_the_close_line_last(): void
    {
        $channel = new SocketPairIQStreamChannel([SocketPairIQStreamChannel::answering()], 'http://iq.example:8080/iq/', self::STREAM, 'pub-token');

        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));
        // A line break in the payload stays inside its line: NDJSON ends a frame at the first one.
        $channel->send(new ProtocolEvent('text-delta', ['delta' => " \"world\"/é\r\n"]));
        $channel->completed($this->state(), 'wf-1');

        $requests = $channel->requests();
        $this->assertCount(1, $requests);
        [$head, $chunks] = SocketPairIQStreamChannel::parse($requests[0]);
        $this->assertSame([
            'POST /iq/' . self::STREAM . ' HTTP/1.1',
            'Host: iq.example:8080',
            'Authorization: Bearer pub-token',
            'Content-Type: application/x-ndjson',
            'Transfer-Encoding: chunked',
            'Connection: close',
        ], explode("\r\n", $head));
        // A chunk per event, written as it was sent: the terminal one carries the close line too.
        $this->assertCount(3, $chunks);
        $lines = SocketPairIQStreamChannel::lines($requests[0]);
        $this->assertSame('{"close":"end"}', $lines[3]);
        // No event name: every frame reaches the subscriber's message listener.
        foreach (array_slice($lines, 0, 3) as $line) {
            $this->assertSame(['data'], array_keys(json_decode($line, true)));
        }
        $envelopes = $this->envelopes($requests[0]);
        $this->assertSame(['text-delta', 'text-delta', 'stream.completed'], array_column($envelopes, 'type'));
        $this->assertSame([0, 1, 2], array_column($envelopes, 'sequence'));
        $this->assertSame(" \"world\"/é\r\n", $envelopes[1]['data']['delta']);
        $this->assertSame(['workflowId' => 'wf-1'], $envelopes[2]['data']);
    }

    /**
     * @return iterable<string, array{Closure(IQStreamChannel, WorkflowState): void, string, string}>
     */
    public static function segmentEndings(): iterable
    {
        yield 'completed' => [static fn (IQStreamChannel $channel, WorkflowState $state) => $channel->completed($state, 'wf-1'), 'stream.completed', '{"close":"end"}'];
        yield 'interrupted' => [static fn (IQStreamChannel $channel, WorkflowState $state) => $channel->interrupted($state), 'stream.interrupted', '{"close":"end"}'];
        yield 'failed' => [static fn (IQStreamChannel $channel) => $channel->failed(new RuntimeException('Boom'), 'wf-1'), 'stream.failed', '{"close":"fail"}'];
    }

    /**
     * @param Closure(IQStreamChannel, WorkflowState): void $end
     */
    #[DataProvider('segmentEndings')]
    public function test_the_terminal_event_is_followed_by_the_close_line_of_its_outcome(Closure $end, string $terminal, string $close): void
    {
        $channel = $this->channel(SocketPairIQStreamChannel::answering());

        $end($channel, $this->state());

        [$request] = $channel->requests();
        $this->assertSame([$terminal], array_column($this->envelopes($request), 'type'));
        $this->assertSame($close, SocketPairIQStreamChannel::lines($request)[1]);
    }

    public function test_a_refusal_before_the_body_ends_stops_the_stream_with_the_server_answer(): void
    {
        $channel = $this->channel(SocketPairIQStreamChannel::refusing(401, '{"error":"unauthorized"}'));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));

        $failure = $this->failure(fn () => $channel->send(new ProtocolEvent('text-delta', ['delta' => ' world'])));

        $this->assertSame('IQ stream ' . self::STREAM . ' stopped: HTTP/1.1 401 Status {"error":"unauthorized"}', $failure->getMessage());
        $this->assertCount(1, $channel->requests());
    }

    public function test_an_answer_that_is_not_the_server_letting_go_stops_the_stream(): void
    {
        // A proxy in front of the server reports a lost upstream with an error of its own.
        $channel = $this->channel(SocketPairIQStreamChannel::refusing(502, '<html>Bad Gateway</html>'));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));

        $failure = $this->failure(fn () => $channel->send(new ProtocolEvent('text-delta', ['delta' => ' world'])));

        $this->assertSame('IQ stream ' . self::STREAM . ' stopped: HTTP/1.1 502 Status', $failure->getMessage());
        $this->assertCount(1, $channel->requests());
    }

    public function test_an_end_of_segment_the_server_refuses_reports_its_answer(): void
    {
        $channel = $this->channel(SocketPairIQStreamChannel::answering(409, '{"error":"stream_closed"}'));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));

        $failure = $this->failure(fn () => $channel->completed($this->state(), 'wf-1'));

        $this->assertSame('IQ stream ' . self::STREAM . ' stopped: HTTP/1.1 409 Status {"error":"stream_closed"}', $failure->getMessage());
        $this->assertCount(1, $channel->requests());
    }

    public function test_an_end_of_segment_the_server_does_not_answer_is_reported(): void
    {
        $channel = $this->channel(SocketPairIQStreamChannel::hangingUp());

        $failure = $this->failure(fn () => $channel->completed($this->state(), 'wf-1'));

        $this->assertSame('IQ stream ' . self::STREAM . ' stopped: no answer', $failure->getMessage());
        $this->assertCount(1, $channel->requests());
    }

    public function test_after_a_refusal_later_events_are_dropped_and_the_terminal_event_goes_over_a_new_request(): void
    {
        $channel = $this->channel(SocketPairIQStreamChannel::refusing(413, '{"error":"frame_too_large"}'), SocketPairIQStreamChannel::answering());
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));
        $this->failure(fn () => $channel->send(new ProtocolEvent('text-delta', ['delta' => ' world'])));

        $channel->send(new ProtocolEvent('text-delta', ['delta' => '!']));
        $channel->completed($this->state(), 'wf-1');

        [, $second] = $channel->requests();
        $envelopes = $this->envelopes($second);
        $this->assertSame(['stream.completed'], array_column($envelopes, 'type'));
        // The refused event keeps its sequence, so a subscriber sees the gap it left.
        $this->assertSame([2], array_column($envelopes, 'sequence'));
        $this->assertSame('{"close":"end"}', SocketPairIQStreamChannel::lines($second)[1]);
    }

    /**
     * @return iterable<string, array{Closure(string): ?string}>
     */
    public static function letGoAnswers(): iterable
    {
        yield 'answered read_timeout' => [SocketPairIQStreamChannel::refusing(408, '{"error":"read_timeout"}')];
        yield 'closed without an answer' => [SocketPairIQStreamChannel::hangingUp()];
    }

    /**
     * @param Closure(string): ?string $letGo
     */
    #[DataProvider('letGoAnswers')]
    public function test_after_the_server_lets_go_of_an_idle_request_the_next_event_continues_the_stream_over_a_new_one(Closure $letGo): void
    {
        $channel = $this->channel($letGo, SocketPairIQStreamChannel::answering());
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));

        $channel->send(new ProtocolEvent('text-delta', ['delta' => ' world']));
        $channel->completed($this->state(), 'wf-1');

        [$first, $second] = $channel->requests();
        $this->assertSame(SocketPairIQStreamChannel::parse($first)[0], SocketPairIQStreamChannel::parse($second)[0]);
        $this->assertSame([0], array_column($this->envelopes($first), 'sequence'));
        $this->assertSame([1, 2], array_column($this->envelopes($second), 'sequence'));
        $this->assertSame('{"close":"end"}', SocketPairIQStreamChannel::lines($second)[2]);
    }

    public function test_a_segment_that_ends_after_the_server_let_go_closes_the_stream_over_a_new_request(): void
    {
        $channel = $this->channel(SocketPairIQStreamChannel::refusing(408, '{"error":"read_timeout"}'), SocketPairIQStreamChannel::answering());
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));

        $channel->failed(new RuntimeException('Boom'), 'wf-1');

        [, $second] = $channel->requests();
        $this->assertSame([1], array_column($this->envelopes($second), 'sequence'));
        $this->assertSame(['{"close":"fail"}'], array_slice(SocketPairIQStreamChannel::lines($second), 1));
    }

    public function test_lines_stay_within_the_frame_limit_and_a_large_event_is_fragmented(): void
    {
        $channel = new SocketPairIQStreamChannel([SocketPairIQStreamChannel::answering()], 'http://iq.example', self::STREAM, 'pub-token', maxFrameBytes: 300);
        $payload = ['output' => str_repeat('a"/é', 300)];

        $channel->send(new ProtocolEvent('tool-output-available', $payload));
        $channel->completed($this->state(), 'wf-1');

        [$request] = $channel->requests();
        foreach (SocketPairIQStreamChannel::lines($request) as $line) {
            $this->assertLessThanOrEqual(300, strlen($line));
        }
        $envelopes = $this->envelopes($request);
        $fragments = array_slice($envelopes, 0, -1);
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
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($closed, false);
        fclose($closed);
        $channel = new IQStreamChannel("http://{$address}", self::STREAM, 'pub-token');

        $failure = $this->failure(fn () => $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello'])));

        $this->assertSame("IQ streaming could not connect to tcp://{$address}: Connection refused", $failure->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'stream ID too short' => ['http://iq.example', str_repeat('a', 15)];
        yield 'stream ID too long' => ['http://iq.example', str_repeat('a', 129)];
        yield 'stream ID with a dot' => ['http://iq.example', 'thread.42.run.0123456789'];
        // It would end the request line early, and the rest would be read as headers.
        yield 'stream ID ending with a line break' => ['http://iq.example', self::STREAM . "\n"];
        yield 'URL without a scheme' => ['iq.example:8080', self::STREAM];
        yield 'URL of another scheme' => ['ws://iq.example', self::STREAM];
        yield 'relative URL' => ['/streams', self::STREAM];
    }

    #[DataProvider('invalidArguments')]
    public function test_the_stream_id_and_the_url_are_validated(string $url, string $stream): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IQStreamChannel($url, $stream, 'pub-token');
    }

    public function test_the_shortest_and_the_longest_stream_id_are_accepted(): void
    {
        $this->expectNotToPerformAssertions();

        new IQStreamChannel('http://iq.example', str_repeat('a', 16), 'pub-token');
        new IQStreamChannel('http://iq.example', str_repeat('a', 128), 'pub-token');
    }

    public function test_streams_an_agent_run_as_the_adapter_events_followed_by_the_completion(): void
    {
        $channel = $this->channel(SocketPairIQStreamChannel::answering());
        $agent = Agent::make()->setThreadId('thread_1')
            ->setStreamAdapter(fn (): VercelAIAdapter => new VercelAIAdapter())
            ->setChannel(fn (): StreamingChannelInterface => $channel);
        $response = 'Hello world from IQ';
        $agent->setAiProvider((new FakeAIProvider(new AssistantMessage($response)))->setStreamChunkSize(5));

        $state = $agent->run(ExecutionRequest::start(new AgentStartEvent([new UserMessage('Hi')], new AgentRunOptions(stream: true))));

        [$request] = $channel->requests();
        $envelopes = $this->envelopes($request);
        $this->assertSame($response, $state->getMessage()->getContent());
        $this->assertSame(
            array_merge(['start', 'text-start'], array_fill(0, count(str_split($response, 5)), 'text-delta'), ['text-end', 'finish', 'stream.completed']),
            array_column($envelopes, 'type'),
        );
        $this->assertSame($response, implode('', array_column(array_column($envelopes, 'data'), 'delta')));
        $this->assertSame('{"close":"end"}', SocketPairIQStreamChannel::lines($request)[count($envelopes)]);
    }
}
