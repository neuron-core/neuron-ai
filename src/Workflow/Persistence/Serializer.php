<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

/**
 * Codec that converts an engine record (a StepResult, an Ignition, a memoized
 * value) to and from raw bytes that a PersistenceInterface backend
 * stores. Owned by the workflow (setSerializer) and applied by WorkflowRunStore —
 * backends store opaque strings, so the storage format (native PHP serialize,
 * igbinary, ...) varies independently of the persistence backends. Each backend
 * owns any encoding required by its storage medium.
 */
interface Serializer
{
    public function serialize(mixed $value): string;

    public function unserialize(string $data): mixed;
}
