<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Stub;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;

/** Returns preset documents and records every query and filter it received. */
class StaticRetrieval implements RetrievalInterface
{
    /** @var array<int, array{query: Message, filters: FilterExpression|null}> */
    public array $received = [];

    /** @param Document[] $documents */
    public function __construct(protected array $documents = [])
    {
    }

    public function retrieve(Message $query, ?FilterExpression $filters = null): array
    {
        $this->received[] = ['query' => $query, 'filters' => $filters];

        return $this->documents;
    }
}
