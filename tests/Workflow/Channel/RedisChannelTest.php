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
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Redis;

use function array_column;
use function array_fill;
use function array_map;
use function count;
use function implode;
use function json_decode;
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
        $channel = $this->channel();
        try {
            $channel->send(new ProtocolEvent('text-delta'));
            $this->fail('Expected publish failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('publish failed', $e->getMessage());
        }
        $channel->send(new ProtocolEvent('text-delta'));
        $this->assertCount(1, $this->redis->published);
        $this->redis->result = 1;
        $channel->completed($this->state(), 'wf-1');
        $this->assertSame('stream.completed', json_decode($this->redis->published[1]['message'], true)['type']);
    }

    public function test_queued_clients_are_rejected_before_a_publish_is_enqueued(): void
    {
        $this->redis->mode = Redis::PIPELINE;
        try {
            $this->channel()->send(new ProtocolEvent('text-delta'));
            $this->fail('Expected a queued-client error.');
        } catch (RuntimeException) {
            $this->assertSame([], $this->redis->published);
        }
    }

    public function test_streams_an_agent_run_as_the_adapter_events_followed_by_the_completion(): void
    {
        $agent = Agent::make()->setStreamAdapter(new VercelAIAdapter())->setChannel($this->channel());
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
