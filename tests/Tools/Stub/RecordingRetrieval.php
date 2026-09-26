<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;

/**
 * Answers every retrieval with the same documents and records what it was asked.
 */
class RecordingRetrieval implements RetrievalInterface
{
    /** @var Message[] */
    public array $queries = [];

    /** @var array<FilterExpression|null> */
    public array $filters = [];

    /**
     * @param Document[] $documents
     */
    public function __construct(protected array $documents = [])
    {
    }

    public function retrieve(Message $query, ?FilterExpression $filters = null): array
    {
        $this->queries[] = $query;
        $this->filters[] = $filters;

        return $this->documents;
    }
}
