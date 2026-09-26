<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use Redis;

/**
 * An unconnected ext-redis client that records what would be published.
 */
final class RecordingRedis extends Redis
{
    /** @var array<int, array{channel: string, message: string}> */
    public array $published = [];

    public int|false $result = 1;

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
}
