<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use NeuronAI\RAG\Document;

abstract class AbstractEmbeddingsProvider implements EmbeddingsProviderInterface
{
    public function embedDocuments(array $documents): array
    {
        foreach ($documents as $index => $document) {
            $documents[$index] = $this->embedDocument($document);
        }

        return $documents;
    }

    public function embedDocument(Document $document): Document
    {
        $document->embedding = $this->embedText($document->content);

        return $document;
    }
}
