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
use NeuronAI\Workflow\Streaming\SSEEncoder;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_map;
use function iterator_to_array;
use function json_encode;

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

    public function test_send_publishes_the_protocol_event_as_json_with_the_type_first(): void
    {
        $event = new ProtocolEvent('text-delta', ['id' => 'msg_1', 'delta' => 'Hello']);

        $this->channel()->send($event);

        $this->assertSame(
            [['channel' => 'chat:42', 'message' => '{"type":"text-delta","id":"msg_1","delta":"Hello"}']],
            $this->redis->published,
        );
        // A subscriber relays the message as an SSE frame without transformation.
        $this->assertSame(SSEEncoder::frame($event), "data: {$this->redis->published[0]['message']}\n\n");
    }

    public function test_lifecycle_events_carry_the_workflow_id_only(): void
    {
        $channel = $this->channel();

        $channel->suspended($this->state());
        $channel->completed($this->state(), 'wf-1');
        $channel->failed(new RuntimeException('internal details'), 'wf-1');

        $this->assertSame(
            [
                '{"type":"stream.suspended","workflowId":"wf-1"}',
                '{"type":"stream.completed","workflowId":"wf-1"}',
                '{"type":"stream.failed","workflowId":"wf-1"}',
            ],
            array_column($this->redis->published, 'message'),
        );
    }

    public function test_invalid_utf8_is_substituted_instead_of_losing_the_message(): void
    {
        $this->channel()->send(new ProtocolEvent('text-delta', ['delta' => "caf\xE9"]));

        $this->assertSame('{"type":"text-delta","delta":"caf\ufffd"}', $this->redis->published[0]['message']);
    }

    public function test_streams_an_agent_run_as_the_adapter_events_followed_by_the_completion(): void
    {
        $agent = Agent::make()->setStreamAdapter(new VercelAIAdapter())->setChannel($this->channel());
        $agent->setAiProvider((new FakeAIProvider(new AssistantMessage('Hello world from Redis')))->setStreamChunkSize(5));

        $pulled = iterator_to_array($agent->stream(new UserMessage('Hi')), false);

        $this->assertSame(
            [...array_map(static fn (ProtocolEvent $event): string => json_encode($event), $pulled), '{"type":"stream.completed","workflowId":"' . $agent->getWorkflowId() . '"}'],
            array_column($this->redis->published, 'message'),
        );
    }
}
