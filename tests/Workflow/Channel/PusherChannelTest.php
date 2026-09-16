<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Streaming\Channel\PusherChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

use function array_fill;
use function array_map;
use function array_merge;
use function base64_decode;
use function count;
use function hash_hmac;
use function implode;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function mb_check_encoding;
use function md5;
use function parse_str;
use function range;
use function str_repeat;
use function strlen;
use function strtr;

class PusherChannelTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    protected array $sent = [];

    protected function channel(int $batchSize = 10, int $maxRequestBytes = 10_000): PusherChannel
    {
        $this->sent = [];
        $responses = array_map(static fn (int $ignored): Response => new Response(200, [], '{}'), range(1, 200));
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        return new PusherChannel(
            channel: 'chat.42',
            appId: 'app-id',
            key: 'app-key',
            secret: 'app-secret',
            host: 'https://api-eu.pusher.com',
            maxRequestBytes: $maxRequestBytes,
            batchSize: $batchSize,
            httpClient: new GuzzleHttpClient(handler: $stack),
        );
    }

    protected function state(): WorkflowState
    {
        $state = new WorkflowState();
        $state->setExecutionMetadata('wf-1', 'run-1', 1);

        return $state;
    }

    /**
     * The batch items of every request, in request order.
     *
     * @return array<int, array<int, array{channel: string, name: string, data: string}>>
     */
    protected function batches(): array
    {
        return array_map(
            static fn (array $exchange): array => json_decode((string) $exchange['request']->getBody(), true)['batch'],
            $this->sent,
        );
    }

    /**
     * Every event name on the wire, in the order a subscriber receives them.
     *
     * @return string[]
     */
    protected function names(): array
    {
        return array_map(static fn (array $item): string => $item['name'], array_merge(...$this->batches()));
    }

    protected function assertRequestsWithinBudget(int $maxRequestBytes, int $batchSize): void
    {
        foreach ($this->sent as $exchange) {
            $this->assertLessThanOrEqual($maxRequestBytes, strlen((string) $exchange['request']->getBody()));
        }
        foreach ($this->batches() as $batch) {
            $this->assertLessThanOrEqual($batchSize, count($batch));
        }
    }

    public function test_send_triggers_a_signed_batch_request_with_the_event_named_by_type(): void
    {
        $channel = $this->channel(batchSize: 1);

        $channel->send(new ProtocolEvent('text-delta', ['id' => 'msg_1', 'delta' => 'Hello']));

        $this->assertCount(1, $this->sent);
        $request = $this->sent[0]['request'];
        $body = (string) $request->getBody();
        parse_str($request->getUri()->getQuery(), $query);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('api-eu.pusher.com', $request->getUri()->getHost());
        $this->assertSame('/apps/app-id/batch_events', $request->getUri()->getPath());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame(
            ['batch' => [['channel' => 'chat.42', 'name' => 'text-delta', 'data' => '{"id":"msg_1","delta":"Hello"}']]],
            json_decode($body, true),
        );

        $this->assertSame('app-key', $query['auth_key']);
        $this->assertSame('1.0', $query['auth_version']);
        $this->assertSame(md5($body), $query['body_md5']);
        $stringToSign = "POST\n/apps/app-id/batch_events\n"
            . "auth_key=app-key&auth_timestamp={$query['auth_timestamp']}&auth_version=1.0&body_md5={$query['body_md5']}";
        $this->assertSame(hash_hmac('sha256', $stringToSign, 'app-secret'), $query['auth_signature']);
    }

    public function test_lifecycle_events_carry_the_workflow_id_only(): void
    {
        $channel = $this->channel(batchSize: 1);

        $channel->interrupted($this->state());
        $channel->completed($this->state(), 'wf-1');
        $channel->failed(new RuntimeException('internal details'), 'wf-1');

        $this->assertSame(
            [
                [['channel' => 'chat.42', 'name' => 'stream.interrupted', 'data' => '{"workflowId":"wf-1"}']],
                [['channel' => 'chat.42', 'name' => 'stream.completed', 'data' => '{"workflowId":"wf-1"}']],
                [['channel' => 'chat.42', 'name' => 'stream.failed', 'data' => '{"workflowId":"wf-1"}']],
            ],
            $this->batches(),
        );
    }

    public function test_events_are_batched_up_to_the_batch_size_and_the_lifecycle_flushes_the_rest(): void
    {
        $channel = $this->channel(batchSize: 3);

        foreach (['a', 'b', 'c', 'd'] as $delta) {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => $delta]));
        }
        $this->assertCount(1, $this->sent);

        $channel->completed($this->state(), 'wf-1');

        $batches = $this->batches();
        $this->assertCount(2, $batches);
        $this->assertSame(['text-delta', 'text-delta', 'text-delta'], array_map(static fn (array $item): string => $item['name'], $batches[0]));
        $this->assertSame(['text-delta', 'stream.completed'], array_map(static fn (array $item): string => $item['name'], $batches[1]));
        $this->assertSame(
            ['{"delta":"a"}', '{"delta":"b"}', '{"delta":"c"}', '{"delta":"d"}', '{"workflowId":"wf-1"}'],
            array_map(static fn (array $item): string => $item['data'], array_merge(...$batches)),
        );
    }

    public function test_a_batch_flushes_when_the_next_event_would_exceed_the_byte_budget(): void
    {
        $channel = $this->channel(batchSize: 10, maxRequestBytes: 1_000);

        foreach (range(1, 5) as $ignored) {
            $channel->send(new ProtocolEvent('text-delta', ['delta' => str_repeat('x', 300)]));
        }
        $channel->completed($this->state(), 'wf-1');

        $this->assertSame([2, 2, 2], array_map(count(...), $this->batches()));
        $this->assertSame('stream.completed', $this->batches()[2][1]['name']);
        $this->assertRequestsWithinBudget(1_000, 10);
    }

    public function test_an_oversized_event_is_split_into_ordered_fragments_that_reassemble_to_the_event(): void
    {
        $channel = $this->channel(batchSize: 1, maxRequestBytes: 2_000);
        $data = ['toolCallId' => 'call_1', 'output' => str_repeat('Napoli è bella / ', 400)];

        $channel->send(new ProtocolEvent('tool-output-available', $data));

        $items = array_merge(...$this->batches());
        $this->assertGreaterThan(1, count($items));
        foreach ($items as $index => $item) {
            $this->assertSame('stream.fragment', $item['name']);
            $fragment = json_decode($item['data'], true);
            $this->assertSame('tool-output-available', $fragment['event']);
            $this->assertSame($index, $fragment['index']);
            $this->assertSame(count($items), $fragment['total']);
        }
        $this->assertRequestsWithinBudget(2_000, 1);

        $encoded = base64_decode(strtr(implode('', array_map(
            static fn (array $item): string => json_decode($item['data'], true)['part'],
            $items,
        )), '-_', '+/'));
        $this->assertTrue(mb_check_encoding($encoded, 'ASCII'), 'A fragment must decode with atob(), which only carries ASCII.');
        $this->assertSame($data, json_decode($encoded, true));
    }

    public function test_a_fragment_leaves_after_the_pending_batch_and_keeps_the_stream_order(): void
    {
        $channel = $this->channel(batchSize: 10, maxRequestBytes: 2_000);

        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'before']));
        $channel->send(new ProtocolEvent('tool-output-available', ['output' => str_repeat('x', 5_000)]));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'after']));
        $channel->completed($this->state(), 'wf-1');

        $names = $this->names();
        $fragments = count($names) - 3;
        $this->assertGreaterThan(1, $fragments);
        $this->assertSame(
            ['text-delta', ...array_fill(0, $fragments, 'stream.fragment'), 'text-delta', 'stream.completed'],
            $names,
        );
        $this->assertSame(['text-delta'], array_map(static fn (array $item): string => $item['name'], $this->batches()[0]));
        $this->assertRequestsWithinBudget(2_000, 10);
    }

    public function test_streams_an_agent_run_as_the_adapter_events_followed_by_the_completion(): void
    {
        $channel = $this->channel();
        $agent = Agent::make()->setStreamAdapter(new VercelAIAdapter())->setChannel($channel);
        $agent->setAiProvider((new FakeAIProvider(new AssistantMessage('Hello world from Pusher, streamed in small chunks')))->setStreamChunkSize(5));

        $pulled = iterator_to_array($agent->stream(new UserMessage('Hi')), false);

        $items = array_merge(...$this->batches());
        $this->assertSame(
            [...array_map(static fn (ProtocolEvent $event): string => $event->type, $pulled), 'stream.completed'],
            array_map(static fn (array $item): string => $item['name'], $items),
        );
        foreach ($pulled as $index => $event) {
            $this->assertSame(json_encode($event->data), $items[$index]['data']);
        }
        $this->assertGreaterThan(1, count($this->sent));
        $this->assertRequestsWithinBudget(10_000, 10);
    }
}
