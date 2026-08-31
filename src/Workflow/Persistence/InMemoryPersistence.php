<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

class InMemoryPersistence implements PersistenceInterface
{
    /**
     * Values keyed by partition then key. The engine hands the backend
     * already-serialized strings, so every stored value is inherently a
     * detached snapshot — mutations to a live record after writing are never
     * visible on get(), matching the durable backends.
     *
     * @var array<string, array<string, string>>
     */
    protected array $storage = [];

    public function get(string $partition, string $key): ?string
    {
        return $this->storage[$partition][$key] ?? null;
    }

    public function initializeIfAbsent(
        string $partition,
        string $conditionKey,
        string $initialValue,
        array $records = [],
    ): bool {
        if (isset($this->storage[$partition][$conditionKey])) {
            return false;
        }

        $records[$conditionKey] = $initialValue;
        foreach ($records as $key => $value) {
            $this->storage[$partition][$key] = $value;
        }

        return true;
    }

    public function writeIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
        array $records,
    ): bool {
        if (($this->storage[$partition][$conditionKey] ?? null) !== $expectedValue) {
            return false;
        }

        foreach ($records as $key => $value) {
            $this->storage[$partition][$key] = $value;
        }

        return true;
    }

    public function deleteIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
    ): bool {
        if (($this->storage[$partition][$conditionKey] ?? null) !== $expectedValue) {
            return false;
        }

        unset($this->storage[$partition]);

        return true;
    }
}
