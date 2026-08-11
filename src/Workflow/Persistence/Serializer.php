<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

/**
 * Codec that converts an engine record (a StepResult, an Ignition, a memoized
 * value) to and from the storable string a PersistenceInterface backend
 * writes. Owned by the workflow (setSerializer) and applied by the executor —
 * backends store opaque strings, so the storage format (native PHP serialize,
 * igbinary, ...) varies independently of the persistence backends.
 */
interface Serializer
{
    public function serialize(mixed $value): string;

    public function unserialize(string $data): mixed;
}
