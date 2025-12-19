<?php

declare(strict_types=1);

namespace NeuronAI\RAG\GraphStore;

/**
 * Represents a graph triplet (subject-relation-object).
 *
 * This is the fundamental unit of information in knowledge graphs.
 */
class Triplet
{
    public function __construct(
        public readonly string $subject,
        public readonly string $relation,
        public readonly string $object,
    ) {
    }

    public static function fromArray(string $subject, string $relation, string $object): self
    {
        return new self($subject, $relation, $object);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public function toArray(): array
    {
        return [$this->subject, $this->relation, $this->object];
    }
}
