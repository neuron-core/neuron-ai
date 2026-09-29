<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function array_map;

class RetrievalTool extends Tool
{
    protected string $name = 'context_retrieval';

    protected ?string $description = 'Search for documents similar to a given query.';

    public function __construct(
        protected RetrievalInterface $retrieval
    ) {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'The query to retrieve documents for.',
                required: true
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(string $query): array
    {
        // Embeddings are useless to the model and would be resent with the history on every turn
        return array_map(fn (Document $document): array => [
            'content' => $document->getContent(),
            'sourceType' => $document->getSourceType(),
            'sourceName' => $document->getSourceName(),
            'score' => $document->getScore(),
            'metadata' => $document->getMetadata(),
        ], $this->retrieval->retrieve(new UserMessage($query)));
    }
}
