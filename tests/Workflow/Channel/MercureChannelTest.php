<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use InvalidArgumentException;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Workflow\Channel\Stub\ManualClockMercureChannel;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\Exception\RuntimeException as PublishException;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;

use function array_column;
use function array_fill;
use function array_keys;
use function array_map;
use function array_merge;
use function array_shift;
use function array_sum;
use function base64_decode;
use function count;
use function implode;
use function json_decode;
use function ksort;
use function parse_str;
use function range;
use function str_repeat;
use function str_split;
use function strlen;
use function strtr;

class MercureChannelTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: string}> */
    protected array $requests = [];

    /**
     * @param list<MockResponse> $responses The hub's first answers; it accepts every later request.
     */
    protected function channel(
        ?float $maxRequestsPerSecond = null,
        int $maxRequestBytes = 1_048_576,
        bool $private = true,
        array $responses = [],
    ): ManualClockMercureChannel {
        $this->requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers'], 'body' => $options['body']];

            return array_shift($responses) ?? new MockResponse('urn:uuid:update');
        });

        return new ManualClockMercureChannel(
            new Hub('https://hub.example/.well-known/mercure', new StaticTokenProvider('header.payload.signature'), httpClient: $client),
            'https://shop.example/threads/42',
            $maxRequestBytes,
            $maxRequestsPerSecond,
            $private,
        );
    }

    protected function refusal(?string $retryAfter = null): MockResponse
    {
        return new MockResponse('Too Many Requests', [
            'http_code' => 429,
            'response_headers' => $retryAfter === null ? [] : ["Retry-After: {$retryAfter}"],
        ]);
    }

    protected function state(): WorkflowState
    {
        $state = new WorkflowState();
        $state->setExecutionMetadata('wf-1', 'run-1', 1);

        return $state;
    }

    /**
     * A request limit that holds one event at a time: the terminal one, the longest sent here.
     */
    protected function singleEventLimit(): int
    {
        $this->channel()->completed($this->state(), 'wf-1');

        return strlen($this->requests[0]['body']);
    }

    /**
     * @return array<string, string>
     */
    protected function fields(int $request): array
    {
        parse_str($this->requests[$request]['body'], $fields);

        return $fields;
    }

    /**
     * The envelopes of every update, in request order.
     *
     * @return list<list<array<string, mixed>>>
     */
    protected function updates(): array
    {
        return array_map(fn (int $request): array => json_decode($this->fields($request)['data'], true), range(0, count($this->requests) - 1));
    }

    /**
     * @return list<list<string>>
     */
    protected function types(): array
    {
        return array_map(static fn (array $update): array => array_column($update, 'type'), $this->updates());
    }

    /**
     * The payload of the fragmented event the updates carry, put back together.
     *
     * @return array<string, mixed>
     */
    protected function reassembled(): array
    {
        $parts = [];
        $total = 0;
        foreach (array_merge(...$this->updates()) as $envelope) {
            if ($envelope['type'] === 'stream.fragment') {
                $parts[$envelope['data']['index']] = $envelope['data']['part'];
                $total = $envelope['data']['total'];
            }
        }
        ksort($parts);
        $this->assertCount($total, $parts);

        return json_decode(base64_decode(strtr(implode('', $parts), '-_', '+/')), true);
    }

    public function test_every_event_is_published_at_once_as_a_private_update_holding_its_envelope(): void
    {
        $channel = $this->channel();
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => ' world']));

        $this->assertCount(2, $this->requests);
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('https://hub.example/.well-known/mercure', $this->requests[0]['url']);
        $this->assertContains('Authorization: Bearer header.payload.signature', $this->requests[0]['headers']);
        $this->assertContains('Content-Type: application/x-www-form-urlencoded', $this->requests[0]['headers']);

        $fields = $this->fields(0);
        $this->assertSame(['topic', 'data', 'private'], array_keys($fields));
        $this->assertSame('https://shop.example/threads/42', $fields['topic']);
        $this->assertSame('on', $fields['private']);

        [$first, $second] = $this->updates();
        $this->assertCount(1, $first);
        $this->assertSame(['streamId', 'sequence', 'type', 'data'], array_keys($first[0]));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first[0]['streamId']);
        $this->assertSame(0, $first[0]['sequence']);
        $this->assertSame('text-delta', $first[0]['type']);
        $this->assertSame(['delta' => 'Hello'], $first[0]['data']);
        $this->assertSame([$first[0]['streamId'], 1, ['delta' => ' world']], [$second[0]['streamId'], $second[0]['sequence'], $second[0]['data']]);
        $this->assertSame([], $channel->waits);
    }

    public function test_a_public_channel_sends_no_private_field(): void
    {
        $this->channel(private: false)->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));

        $this->assertSame(['topic', 'data'], array_keys($this->fields(0)));
    }

    public function test_a_closed_slot_packs_events_until_the_next_one_opens(): void
    {
        $channel = $this->channel(maxRequestsPerSecond: 1);
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'a']));
        $channel->time += 0.25;
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'b']));
        $channel->time += 0.25;
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'c']));
        $this->assertCount(1, $this->requests);

        $channel->time += 0.5;
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'd']));

        [$first, $second] = $this->updates();
        $this->assertSame(['a'], array_column(array_column($first, 'data'), 'delta'));
        $this->assertSame(['b', 'c', 'd'], array_column(array_column($second, 'data'), 'delta'));
        $this->assertSame([1, 2, 3], array_column($second, 'sequence'));
        $this->assertSame([], $channel->waits);
    }

    public function test_the_segment_end_waits_for_the_slot_and_sends_the_terminal_event_in_its_own_update(): void
    {
        $channel = $this->channel(maxRequestsPerSecond: 2);
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'a']));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'b']));
        $channel->completed($this->state(), 'wf-1');

        $this->assertSame([['text-delta'], ['text-delta'], ['stream.completed']], $this->types());
        $this->assertSame(['workflowId' => 'wf-1'], $this->updates()[2][0]['data']);
        $this->assertSame([0.5, 0.5], $channel->waits);
    }

    public function test_a_full_request_leaves_at_the_next_slot(): void
    {
        $channel = $this->channel(maxRequestsPerSecond: 1, maxRequestBytes: $this->singleEventLimit());
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'a']));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'b']));
        $this->assertCount(1, $this->requests);

        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'c']));

        $this->assertSame([['a'], ['b']], array_map(static fn (array $update): array => array_column(array_column($update, 'data'), 'delta'), $this->updates()));
        $this->assertSame([1.0], $channel->waits);
    }

    public function test_requests_stay_within_the_byte_limit_and_a_large_event_is_fragmented(): void
    {
        $data = ['toolCallId' => 'call_1', 'content' => str_repeat('The order was shipped on Monday. ', 3_000)];

        $channel = $this->channel(maxRequestsPerSecond: 1, maxRequestBytes: 15_000);
        $channel->send(new ProtocolEvent('TOOL_CALL_RESULT', $data));

        // One fragment per slot; the last one is a partial update, which waits for what comes next.
        $this->assertCount(8, $this->requests);
        $this->assertSame(array_fill(0, 7, 1.0), $channel->waits);

        $channel->completed($this->state(), 'wf-1');

        $this->assertSame([...array_fill(0, 9, ['stream.fragment']), ['stream.completed']], $this->types());
        foreach ($this->requests as $request) {
            $this->assertLessThanOrEqual(15_000, strlen($request['body']));
        }
        $this->assertSame($data, $this->reassembled());
        $this->assertSame(array_fill(0, 9, 1.0), $channel->waits);
    }

    public function test_the_byte_limit_is_measured_on_the_request_the_hub_receives(): void
    {
        $event = new ProtocolEvent('text-delta', ['delta' => str_repeat('Hello, "world" ~ {1: 2} ', 100)]);
        $this->channel()->send($event);
        $bytes = strlen($this->requests[0]['body']);

        $this->channel(maxRequestBytes: $bytes)->send($event);
        $this->assertSame([['text-delta']], $this->types());
        $this->assertSame($bytes, strlen($this->requests[0]['body']));

        $this->channel(maxRequestBytes: $bytes - 1)->send($event);
        $this->assertSame('stream.fragment', $this->types()[0][0]);
        foreach ($this->requests as $request) {
            $this->assertLessThan($bytes, strlen($request['body']));
        }
        $this->assertSame($event->data, $this->reassembled());
    }

    public function test_fragments_of_every_payload_length_fit_the_limit(): void
    {
        foreach (range(4_000, 4_060) as $length) {
            $data = ['content' => str_repeat('a', $length)];
            $this->channel(maxRequestBytes: 2_000)->send(new ProtocolEvent('text-delta', $data));

            foreach ($this->requests as $request) {
                $this->assertLessThanOrEqual(2_000, strlen($request['body']));
            }
            $this->assertSame($data, $this->reassembled());
        }
    }

    public function test_a_rate_limited_update_is_sent_again_after_the_delay_the_hub_asks_for(): void
    {
        $channel = $this->channel(responses: [$this->refusal('2')]);
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));

        $this->assertCount(2, $this->requests);
        $this->assertSame($this->requests[0]['body'], $this->requests[1]['body']);
        $this->assertSame([2.0], $channel->waits);
    }

    public function test_a_rate_limit_without_a_delay_is_retried_after_one_second(): void
    {
        $channel = $this->channel(responses: [$this->refusal(), $this->refusal('Wed, 21 Oct 2026 07:28:00 GMT')]);
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));

        $this->assertCount(3, $this->requests);
        $this->assertSame([1.0, 1.0], $channel->waits);
    }

    public function test_a_refused_paced_channel_keeps_packing_until_the_hub_accepts(): void
    {
        $channel = $this->channel(maxRequestsPerSecond: 1, responses: [new MockResponse('urn:uuid:update'), $this->refusal('3')]);
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'a']));
        $channel->time += 1.0;
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'b']));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'c']));
        $channel->time += 1.0;
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'd']));

        $this->assertSame(
            [['a'], ['b'], ['b'], ['c', 'd']],
            array_map(static fn (array $update): array => array_column(array_column($update, 'data'), 'delta'), $this->updates()),
        );
        $this->assertSame([3.0], $channel->waits);
    }

    public function test_another_hub_failure_stops_the_stream_without_a_retry_but_allows_the_terminal(): void
    {
        $channel = $this->channel(responses: [new MockResponse('Unauthorized', ['http_code' => 401])]);

        try {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));
            $this->fail('The hub failure should surface.');
        } catch (PublishException $e) {
            $this->assertSame('Failed to send an update.', $e->getMessage());
        }

        $channel->send(new ProtocolEvent('text-delta', ['delta' => ' world']));
        $this->assertCount(1, $this->requests);

        $channel->completed($this->state(), 'wf-1');
        $this->assertSame(['stream.completed'], $this->types()[1]);
        $this->assertSame([], $channel->waits);
    }

    public function test_a_hub_that_keeps_refusing_is_given_up_after_ten_seconds_of_waiting(): void
    {
        $channel = $this->channel(responses: array_fill(0, 5, $this->refusal('4')));

        try {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));
            $this->fail('The channel should give the segment up.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('would hold the run for more than 10 seconds', $e->getMessage());
        }

        $this->assertCount(3, $this->requests);
        $this->assertSame([4.0, 4.0], $channel->waits);

        $channel->send(new ProtocolEvent('text-delta', ['delta' => ' world']));
        $this->assertCount(3, $this->requests);
    }

    public function test_a_wait_longer_than_ten_seconds_is_refused_without_waiting(): void
    {
        $channel = $this->channel(responses: [$this->refusal('60')]);

        try {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => 'Hello']));
            $this->fail('The channel should give the segment up.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('would hold the run for more than 10 seconds', $e->getMessage());
        }

        $this->assertCount(1, $this->requests);
        $this->assertSame([], $channel->waits);
    }

    public function test_an_event_that_freezes_the_run_for_more_than_ten_seconds_is_given_up(): void
    {
        $channel = $this->channel(maxRequestsPerSecond: 1, maxRequestBytes: 15_000);

        try {
            $channel->send(new ProtocolEvent('TOOL_CALL_RESULT', ['content' => str_repeat('The order was shipped on Monday. ', 30_000)]));
            $this->fail('The channel should give the segment up.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('would hold the run for more than 10 seconds', $e->getMessage());
        }

        $this->assertCount(11, $this->requests);
        $this->assertSame(array_fill(0, 10, 1.0), $channel->waits);
    }

    public function test_the_time_a_run_spends_working_pays_its_waiting_back(): void
    {
        // Every request holds one event, so each event waits half a second for its slot, then the run works half a second.
        $channel = $this->channel(maxRequestsPerSecond: 1, maxRequestBytes: $this->singleEventLimit());
        foreach (range(1, 40) as $ignored) {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => 'a']));
            $channel->time += 0.5;
        }

        $this->assertCount(39, $this->requests);
        $this->assertGreaterThan(10.0, array_sum($channel->waits));
    }

    #[DataProvider('unusableRates')]
    public function test_the_rate_must_allow_three_requests_within_ten_seconds(float $maxRequestsPerSecond): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mercure request rate must allow three requests every 10 seconds');

        $this->channel(maxRequestsPerSecond: $maxRequestsPerSecond);
    }

    /** @return iterable<string, array{float}> */
    public static function unusableRates(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'one request every four seconds' => [0.25];
    }

    public function test_the_slowest_usable_rate_still_ends_a_segment_that_needs_three_requests_in_a_row(): void
    {
        $channel = $this->channel(maxRequestsPerSecond: 0.32, maxRequestBytes: $this->singleEventLimit());
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'a']));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'b']));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'c']));
        $channel->completed($this->state(), 'wf-1');

        $this->assertSame([['text-delta'], ['text-delta'], ['text-delta'], ['stream.completed']], $this->types());
        $this->assertEqualsWithDelta([3.125, 3.125, 3.125], $channel->waits, 0.001);
    }

    public function test_streams_an_agent_run_as_the_adapter_events_followed_by_the_completion(): void
    {
        $channel = $this->channel(maxRequestsPerSecond: 4);
        $agent = Agent::make()->setThreadId('thread_1')->setStreamAdapter(fn (): VercelAIAdapter => new VercelAIAdapter())->setChannel(fn (): StreamingChannelInterface => $channel);
        $response = 'Hello world from Mercure';
        $agent->setAiProvider((new FakeAIProvider(new AssistantMessage($response)))->setStreamChunkSize(5));

        $state = $agent->run(ExecutionRequest::start(new AgentStartEvent([new UserMessage('Hi')], new AgentRunOptions(stream: true))));

        $envelopes = array_merge(...$this->updates());
        $this->assertSame($response, $state->getMessage()->getContent());
        $this->assertSame(
            ['start', 'text-start', ...array_fill(0, count(str_split($response, 5)), 'text-delta'), 'text-end', 'finish', 'stream.completed'],
            array_column($envelopes, 'type'),
        );
        $this->assertSame($response, implode('', array_column(array_column($envelopes, 'data'), 'delta')));
        foreach ($envelopes as $index => $envelope) {
            $this->assertSame($index, $envelope['sequence']);
        }
        // The first event left at once, the rest when the segment ended, the terminal event one slot later.
        $this->assertCount(3, $this->requests);
        $this->assertSame([0.25, 0.25], $channel->waits);
    }
}
