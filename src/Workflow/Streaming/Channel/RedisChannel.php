<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use JsonException;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use Redis;
use Throwable;

use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

final class RedisChannel implements StreamingChannelInterface
{
    public function __construct(
        protected Redis $client,
        protected string $channel,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function send(ProtocolEvent $event): void
    {
        $this->publish($event);
    }

    /**
     * @throws JsonException
     */
    public function interrupted(WorkflowState $state): void
    {
        $this->publish(new ProtocolEvent('stream.interrupted', ['workflowId' => $state->getWorkflowId()]));
    }

    /**
     * @throws JsonException
     */
    public function completed(WorkflowState $state, string $workflowId): void
    {
        $this->publish(new ProtocolEvent('stream.completed', ['workflowId' => $workflowId]));
    }

    /**
     * @throws JsonException
     */
    public function failed(Throwable $exception, string $workflowId): void
    {
        $this->publish(new ProtocolEvent('stream.failed', ['workflowId' => $workflowId]));
    }

    /**
     * Invalid UTF-8 becomes U+FFFD instead of a lost message, as on the SSE path.
     *
     * @throws JsonException
     */
    protected function publish(ProtocolEvent $event): void
    {
        $this->client->publish($this->channel, json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
