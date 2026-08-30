<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

interface FilterExpression
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
