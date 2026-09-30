<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Workflow\Channel\Stub\RecordingRedis;
use NeuronAI\Workflow\Streaming\Channel\RedisChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Redis;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;

use function array_column;
use function array_fill;
use function array_map;
use function count;
use function implode;
use function json_decode;
use function microtime;
use function str_split;

class RedisChannelTest extends TestCase
{
    protected RecordingRedis $redis;

    protected function setUp(): void
    {
        $this->redis = new RecordingRedis();
    }

    protected function channel(): RedisChannel
    {
        return new RedisChannel($this->redis, 'chat:42');
    }

    protected function state(): WorkflowState
    {
        $state = new WorkflowState();
        $state->setExecutionMetadata('wf-1', 'run-1', 1);

        return $state;
    }

    public function test_send_publishes_the_shared_envelope(): void
    {
        $event = new ProtocolEvent('text-delta', ['id' => 'msg_1', 'delta' => 'Hello']);
        $this->channel()->send($event);
        $this->assertSame('chat:42', $this->redis->published[0]['channel']);
        $envelope = json_decode($this->redis->published[0]['message'], true);
        $this->assertSame($event->type, $envelope['type']);
        $this->assertSame($event->data, $envelope['data']);
        $this->assertSame(0, $envelope['sequence']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $envelope['streamId']);
    }

    public function test_invalid_utf8_is_substituted_instead_of_losing_the_message(): void
    {
        $this->channel()->send(new ProtocolEvent('text-delta', ['delta' => "caf\xE9"]));
        $envelope = json_decode($this->redis->published[0]['message'], true);
        $this->assertSame("caf\u{FFFD}", $envelope['data']['delta']);
    }

    public function test_a_zero_subscriber_count_is_successful(): void
    {
        $this->redis->result = 0;
        $channel = $this->channel();
        $channel->send(new ProtocolEvent('text-delta'));
        $channel->send(new ProtocolEvent('text-delta'));
        $this->assertCount(2, $this->redis->published);
    }

    public function test_false_publish_results_stop_data_delivery_but_allow_the_terminal(): void
    {
        $this->redis->result = false;
        $this->redis->lastError = 'NOAUTH Authentication required.';
        $channel = $this->channel();
        try {
            $channel->send(new ProtocolEvent('text-delta'));
            $this->fail('Expected publish failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Redis streaming publish failed: NOAUTH Authentication required.', $e->getMessage());
        }
        $channel->send(new ProtocolEvent('text-delta'));
        $this->assertCount(1, $this->redis->published);
        $this->redis->result = 1;
        $channel->completed($this->state(), 'wf-1');
        $this->assertSame('stream.completed', json_decode($this->redis->published[1]['message'], true)['type']);
    }

    /** @return array<string, array{int}> */
    public static function queuedModeProvider(): array
    {
        return ['transaction' => [Redis::MULTI], 'pipeline' => [Redis::PIPELINE]];
    }

    #[DataProvider('queuedModeProvider')]
    public function test_queued_clients_are_rejected_before_a_publish_is_enqueued(int $mode): void
    {
        $this->redis->mode = $mode;
        try {
            $this->channel()->send(new ProtocolEvent('text-delta'));
            $this->fail('Expected a queued-client error.');
        } catch (RuntimeException $e) {
            $this->assertSame('Redis streaming cannot run inside a transaction or pipeline.', $e->getMessage());
        }
        $this->assertSame([], $this->redis->published);
    }

    public function test_without_await_listener_no_subscriber_is_counted(): void
    {
        $this->channel()->send(new ProtocolEvent('text-delta'));

        $this->assertSame(0, $this->redis->subscriberCounts);
        $this->assertCount(1, $this->redis->published);
    }

    public function test_the_first_publish_waits_until_a_listener_subscribes(): void
    {
        $this->redis->subscribers = [0, 0, 1];
        $channel = new RedisChannel($this->redis, 'chat:42', awaitListener: 5);

        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'a']));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'b']));

        $this->assertSame(3, $this->redis->subscriberCounts);
        $this->assertCount(2, $this->redis->published);
    }

    public function test_the_first_publish_goes_out_when_no_listener_subscribes_in_time(): void
    {
        $this->redis->subscribers = [0];
        $started = microtime(true);

        (new RedisChannel($this->redis, 'chat:42', awaitListener: 0.2))->send(new ProtocolEvent('text-delta'));

        $this->assertGreaterThanOrEqual(0.2, microtime(true) - $started);
        $this->assertCount(1, $this->redis->published);
    }

    public function test_a_failed_subscriber_count_stops_data_delivery_but_allows_the_terminal(): void
    {
        $this->redis->subscribers = [false];
        $this->redis->lastError = "NOPERM User has no permissions to run the 'pubsub|numsub' command";
        $channel = new RedisChannel($this->redis, 'chat:42', awaitListener: 5);
        try {
            $channel->send(new ProtocolEvent('text-delta'));
            $this->fail('Expected a subscriber count failure.');
        } catch (RuntimeException $e) {
            $this->assertSame("Redis streaming could not count subscribers: NOPERM User has no permissions to run the 'pubsub|numsub' command", $e->getMessage());
        }

        $channel->completed($this->state(), 'wf-1');

        $this->assertSame(1, $this->redis->subscriberCounts);
        $this->assertSame('stream.completed', json_decode($this->redis->published[0]['message'], true)['type']);
    }

    public function test_every_event_is_published_as_its_own_message_on_the_configured_channel(): void
    {
        $channel = $this->channel();
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'a']));
        $channel->send(new ProtocolEvent('text-delta', ['delta' => 'b']));
        $channel->interrupted($this->state());

        $this->assertSame(['chat:42', 'chat:42', 'chat:42'], array_column($this->redis->published, 'channel'));
        $envelopes = array_map(static fn (array $publication): array => json_decode($publication['message'], true), $this->redis->published);
        $this->assertSame(['text-delta', 'text-delta', 'stream.interrupted'], array_column($envelopes, 'type'));
        $this->assertSame([0, 1, 2], array_column($envelopes, 'sequence'));
        $this->assertSame(['workflowId' => 'wf-1'], $envelopes[2]['data']);
    }

    public function test_streams_an_agent_run_as_the_adapter_events_followed_by_the_completion(): void
    {
        $agent = Agent::make()->setThreadId('thread_1')->setStreamAdapter(fn (): VercelAIAdapter => new VercelAIAdapter())->setChannel(fn (): StreamingChannelInterface => $this->channel());
        $response = 'Hello world from Redis';
        $agent->setAiProvider((new FakeAIProvider(new AssistantMessage($response)))->setStreamChunkSize(5));

        $state = $agent->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(new \NeuronAI\Agent\Events\AgentStartEvent([new UserMessage('Hi')], new \NeuronAI\Agent\AgentRunOptions(stream: true))));

        $envelopes = array_map(static fn (array $publication): array => json_decode($publication['message'], true), $this->redis->published);
        $this->assertSame($response, $state->getMessage()->getContent());
        $this->assertSame(
            ['start', 'text-start', ...array_fill(0, count(str_split($response, 5)), 'text-delta'), 'text-end', 'finish', 'stream.completed'],
            array_column($envelopes, 'type'),
        );
        $this->assertSame($response, implode('', array_column(array_column($envelopes, 'data'), 'delta')));
        foreach ($envelopes as $index => $envelope) {
            $this->assertSame($index, $envelope['sequence']);
        }
    }
}
