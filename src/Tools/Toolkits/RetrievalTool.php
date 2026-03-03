<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class RetrievalTool extends Tool
{
    public function __construct(
        protected RetrievalInterface $retrieval
    ) {
        parent::__construct(
            name: 'context_retrieval',
            description: 'Search for documents similar to a given query.',
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'The query to retrieve documents for.',
                required: true
            )
        ];
    }

    public function __invoke(string $query): array
    {
        return $this->retrieval->retrieve(new UserMessage($query));
    }
}
