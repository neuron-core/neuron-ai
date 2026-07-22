<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Workflow\Executor\StepResult;

/**
 * Codec that converts a StepResult to and from the storable string a
 * PersistenceInterface backend writes. Lets the storage format (native PHP
 * serialize, igbinary, JSON, ...) vary independently of the persistence backends.
 */
interface Serializer
{
    public function serialize(StepResult $result): string;

    public function unserialize(string $data): StepResult;
}
