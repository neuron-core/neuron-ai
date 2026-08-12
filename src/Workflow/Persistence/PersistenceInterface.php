<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

/**
 * The single durable store of the workflow engine: string values filed under
 * (partition, key). One artifact per backend — one table, one directory, one
 * array — holding every engine record.
 *
 * The engine owns all record semantics and serialization: a run's records
 * (ignition, steps, memos) live in the partition named by its address —
 * a business key or a generated handle. Backends never interpret partition
 * names and never parse values — both are opaque strings.
 */
interface PersistenceInterface
{
    /**
     * Store a value under (partition, key), overwriting any previous value.
     */
    public function put(string $partition, string $key, string $value): void;

    /**
     * The value stored under (partition, key), or null when absent.
     */
    public function get(string $partition, string $key): ?string;

    /**
     * Remove a whole partition and everything in it.
     */
    public function delete(string $partition): void;
}
