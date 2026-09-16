<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use Redis;
use Throwable;

use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * Publishes a run segment on a Redis Pub/Sub channel, the usual fan-out
 * between a worker running the agent and the process holding the client's
 * connection (an SSE endpoint, a websocket server). Requires ext-redis and a
 * connected client outside a transaction or pipeline.
 *
 * Every message is one protocol event as JSON with the type first, exactly
 * what SSEEncoder::frame() puts after "data: ", so a subscriber relays it to
 * the browser without transformation. The segment lifecycle arrives the same
 * way as stream.interrupted / stream.completed / stream.failed, carrying the
 * workflowId only: what a client learns about an error is the adapter's
 * decision, through its own error frame.
 *
 * Pub/Sub does not replay, which matches the framework's contract: yielded
 * output is ephemeral and chat history is the record a client reconciles
 * from. A RedisException propagates and is reported by the Workflow as a
 * ChannelError without failing the run.
 */
final class RedisChannel implements StreamingChannelInterface
{
    public function __construct(
        protected Redis $client,
        protected string $channel,
    ) {
    }

    public function send(ProtocolEvent $event): void
    {
        $this->publish($event);
    }

    public function interrupted(WorkflowState $state): void
    {
        $this->publish(new ProtocolEvent('stream.interrupted', ['workflowId' => $state->getWorkflowId()]));
    }

    public function completed(WorkflowState $state, string $workflowId): void
    {
        $this->publish(new ProtocolEvent('stream.completed', ['workflowId' => $workflowId]));
    }

    public function failed(Throwable $exception, string $workflowId): void
    {
        $this->publish(new ProtocolEvent('stream.failed', ['workflowId' => $workflowId]));
    }

    /**
     * Invalid UTF-8 becomes U+FFFD instead of a lost message, as on the SSE path.
     */
    protected function publish(ProtocolEvent $event): void
    {
        $this->client->publish($this->channel, json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
