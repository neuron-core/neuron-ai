<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use Redis;

use function array_shift;
use function count;

/**
 * An unconnected ext-redis client that records what would be published.
 */
final class RecordingRedis extends Redis
{
    /** @var array<int, array{channel: string, message: string}> */
    public array $published = [];

    public int|false $result = 1;

    /** @var list<int|false> NUMSUB answers in order; the last one repeats. */
    public array $subscribers = [1];

    public int $subscriberCounts = 0;

    public int $mode = Redis::ATOMIC;

    public ?string $lastError = null;

    public function getMode(): int
    {
        return $this->mode;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function publish(string $channel, string $message): int|false
    {
        $this->published[] = ['channel' => $channel, 'message' => $message];

        return $this->result;
    }

    public function pubsub(string $command, mixed $arg = null): mixed
    {
        ++$this->subscriberCounts;
        $subscribers = count($this->subscribers) > 1 ? array_shift($this->subscribers) : $this->subscribers[0];

        // A prefixed client answers under the prefixed channel name.
        return $subscribers === false ? false : ["app_{$arg[0]}" => $subscribers];
    }
}
