<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence\Stub;

use Redis;
use RedisException;

/**
 * An unconnected ext-redis client whose scripts fail: by raising the given
 * exception, or otherwise by returning false with the error left in
 * getLastError().
 */
final class ScriptFailingRedis extends Redis
{
    /** @var list<array{script: string, args: array<int, string>, keys: int}> */
    public array $evaluated = [];

    public ?RedisException $exception = null;

    public function getMode(): int
    {
        return Redis::ATOMIC;
    }

    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $this->evaluated[] = ['script' => $script, 'args' => $args, 'keys' => $num_keys];
        if ($this->exception instanceof RedisException) {
            throw $this->exception;
        }

        return false;
    }

    public function getLastError(): string
    {
        return 'ERR script failed';
    }
}
