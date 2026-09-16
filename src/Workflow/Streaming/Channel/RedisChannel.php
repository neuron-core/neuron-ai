<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use Redis;
use RuntimeException;

final class RedisChannel extends AbstractChannel
{
    public function __construct(
        protected Redis $client,
        protected string $channel,
    ) {
    }

    protected function deliver(string $batch): void
    {
        if ($this->client->getMode() !== Redis::ATOMIC) {
            throw new RuntimeException('Redis streaming cannot run inside a transaction or pipeline.');
        }
        if ($this->client->publish($this->channel, $batch) === false) {
            throw new RuntimeException('Redis streaming publish failed: ' . $this->client->getLastError());
        }
    }
}
