<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use Redis;
use RuntimeException;

use function array_sum;
use function microtime;
use function usleep;

final class RedisChannel extends AbstractChannel
{
    protected bool $listenerAwaited = false;

    /**
     * @param float $awaitListener Seconds the first publish waits for a subscriber. Pub/Sub keeps
     *                             nothing, so a reader subscribing after the worker starts (the
     *                             RedisChannelReader behind an HTTP response) needs a few seconds.
     */
    public function __construct(
        protected Redis $client,
        protected string $channel,
        protected float $awaitListener = 0,
    ) {
    }

    protected function deliver(string $batch): void
    {
        if ($this->client->getMode() !== Redis::ATOMIC) {
            throw new RuntimeException('Redis streaming cannot run inside a transaction or pipeline.');
        }
        if (!$this->listenerAwaited) {
            $this->listenerAwaited = true;
            $this->waitForListener();
        }
        if ($this->client->publish($this->channel, $batch) === false) {
            throw new RuntimeException('Redis streaming publish failed: ' . $this->client->getLastError());
        }
    }

    protected function waitForListener(): void
    {
        $deadline = microtime(true) + $this->awaitListener;
        while (microtime(true) < $deadline && $this->listeners() < 1) {
            usleep(50_000);
        }
    }

    protected function listeners(): int
    {
        $counts = $this->client->pubsub('numsub', [$this->channel]);
        if ($counts === false) {
            throw new RuntimeException('Redis streaming could not count subscribers: ' . $this->client->getLastError());
        }

        // NUMSUB answers under the client's key prefix: count, never index by the channel name.
        return (int) array_sum($counts);
    }
}
