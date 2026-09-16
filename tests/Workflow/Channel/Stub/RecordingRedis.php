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

    public function publish(string $channel, string $message): int
    {
        $this->published[] = ['channel' => $channel, 'message' => $message];

        return 1;
    }
}
