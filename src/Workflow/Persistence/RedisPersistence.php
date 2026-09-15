<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Exceptions\PersistenceException;
use Redis;
use RedisException;

/**
 * Requires ext-redis and a connected client outside a transaction or pipeline.
 * Each partition is one Redis hash. Lua keeps comparisons and mutations atomic
 * and bypasses client serialization so records remain opaque bytes.
 * Configure Redis durability and eviction to suit the workflow's retention needs.
 */
class RedisPersistence implements PersistenceInterface
{
    public function __construct(
        protected Redis $client,
        protected string $prefix = 'neuron:workflow:',
    ) {
    }

    /**
     * @throws PersistenceException
     */
    public function get(string $partition, string $key): ?string
    {
        $value = $this->evaluate("return redis.call('HGET', KEYS[1], ARGV[1]) or 0", $partition, [$key]);

        return $value === 0 ? null : $value;
    }

    /**
     * @throws PersistenceException
     */
    public function initializeIfAbsent(
        string $partition,
        string $conditionKey,
        string $initialValue,
        array $records = [],
    ): bool {
        $records[$conditionKey] = $initialValue;

        return $this->commitIf($partition, $conditionKey, null, $records);
    }

    /**
     * @throws PersistenceException
     */
    public function writeIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
        array $records,
    ): bool {
        return $this->commitIf($partition, $conditionKey, $expectedValue, $records);
    }

    /**
     * @throws PersistenceException
     */
    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
        return $this->commitIf($partition, $conditionKey, $expectedValue, deletePartition: true);
    }

    /**
     * @param array<string, string> $records
     * @throws PersistenceException
     */
    protected function commitIf(
        string $partition,
        string $conditionKey,
        ?string $expectedValue,
        array $records = [],
        bool $deletePartition = false,
    ): bool {
        $operation = $expectedValue === null ? 'initialize' : ($deletePartition ? 'delete' : 'write');
        $arguments = [$operation, $conditionKey, $expectedValue ?? ''];
        foreach ($records as $key => $value) {
            $arguments[] = (string) $key;
            $arguments[] = $value;
        }

        // One HSET avoids partial writes if Redis rejects the mutation.
        return $this->evaluate(<<<'LUA'
            local current = redis.call('HGET', KEYS[1], ARGV[2])
            if ARGV[1] == 'initialize' then
                if current ~= false then
                    return 0
                end
            elseif current ~= ARGV[3] then
                return 0
            end

            if ARGV[1] == 'delete' then
                redis.call('DEL', KEYS[1])
            elseif #ARGV > 3 then
                redis.call('HSET', KEYS[1], unpack(ARGV, 4))
            end
            return 1
            LUA, $partition, $arguments) === 1;
    }

    /**
     * @param list<string> $arguments
     * @throws PersistenceException
     */
    protected function evaluate(string $script, string $partition, array $arguments): mixed
    {
        if ($this->client->getMode() !== Redis::ATOMIC) {
            throw new PersistenceException('Workflow Redis persistence cannot run inside a transaction or pipeline.');
        }

        try {
            $result = $this->client->eval($script, [$this->prefix . $partition, ...$arguments], 1);
        } catch (RedisException $e) {
            throw new PersistenceException('Workflow Redis persistence failed: ' . $e->getMessage(), $e->getCode(), previous: $e);
        }

        if ($result === false) {
            throw new PersistenceException('Workflow Redis persistence failed: ' . $this->client->getLastError());
        }

        return $result;
    }
}
