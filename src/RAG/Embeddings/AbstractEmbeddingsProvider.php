<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\RAG\Document;

use function array_diff_key;
use function count;

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
        $document->setEmbedding($this->embedText($document->getContent()));

        return $document;
    }

    /**
     * The whole batch response is checked before any embedding is set: a document it missed
     * would otherwise keep no embedding, or the one it carried before, without any error.
     *
     * @param Document[] $documents The documents of one request, in the order they were sent
     * @param array<int, array<int, int|float>> $embeddings Keyed by the position of the document they embed
     * @throws ProviderException
     */
    protected function assignEmbeddings(array $documents, array $embeddings): void
    {
        if (count($embeddings) !== count($documents) || array_diff_key($documents, $embeddings) !== []) {
            throw new ProviderException('The embeddings response does not have one embedding for each of the '.count($documents).' documents sent.');
        }

        foreach ($documents as $position => $document) {
            $document->setEmbedding($embeddings[$position]);
        }
    }
}
