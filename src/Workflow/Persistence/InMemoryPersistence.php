<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

class InMemoryPersistence implements PersistenceInterface
{
    /**
     * Values keyed by partition then key. The engine hands the backend
     * already-serialized strings, so every stored value is inherently a
     * detached snapshot — mutations to a live record after put() are never
     * visible on get(), matching the durable backends.
     *
     * @var array<string, array<string, string>>
     */
    protected array $storage = [];

    public function put(string $partition, string $key, string $value): void
    {
        $this->storage[$partition][$key] = $value;
    }

    public function get(string $partition, string $key): ?string
    {
        return $this->storage[$partition][$key] ?? null;
    }

    public function delete(string $partition): void
    {
        unset($this->storage[$partition]);
    }
}
