<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Stub;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

use function array_slice;

/** Keeps the first documents and records every call it received. */
class LimitPostProcessor implements PostProcessorInterface
{
    /** @var array<int, array{question: Message, documents: Document[]}> */
    public array $received = [];

    public function __construct(protected int $limit)
    {
    }

    public function process(Message $question, array $documents): array
    {
        $this->received[] = ['question' => $question, 'documents' => $documents];

        return array_slice($documents, 0, $this->limit);
    }
}
