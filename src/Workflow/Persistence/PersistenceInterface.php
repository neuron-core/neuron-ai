<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

/**
 * The single durable store of the workflow engine: string values filed under
 * (partition, key). One artifact per backend — one table, one directory, one
 * array — holding every engine record.
 *
 * The engine owns all record semantics and serialization: a run's records
 * (ignition, steps, memos) live in the partition named by its workflow ID —
 * a business key or a generated handle. Backends never interpret partition
 * names and never parse values — both are opaque strings.
 *
 * A partition name is an ARBITRARY non-empty string: workflow IDs are
 * business-owned (a threadId, 'order:123'), so safe storage of any name is
 * the backend's obligation — encode names that are unsafe for the medium
 * (e.g. as filenames) at the backend's own boundary. There are no reserved
 * partitions — backends treat every name uniformly; the engine keeps the
 * "__" name prefix reclaimable by refusing such workflow IDs at its own layer.
 * Capacity limits (name length, value size) are backend-specific and must
 * surface as loud failures, never silent truncation or a dropped write.
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
