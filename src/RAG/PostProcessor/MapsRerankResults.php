<?php

declare(strict_types=1);

namespace NeuronAI\RAG\PostProcessor;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\RAG\Document;

use function array_values;
use function is_array;
use function is_float;
use function is_int;
use function json_encode;

/**
 * Maps a rerank API answer, which names documents by their position in the request, back to them.
 */
trait MapsRerankResults
{
    /**
     * @param list<Document> $documents The documents in the order they were sent
     * @param array<mixed> $response
     * @return list<Document> The ranked documents, in the order of the answer
     * @throws ProviderException
     */
    protected function rankedDocuments(array $documents, array $response): array
    {
        if (!is_array($response['results'] ?? null)) {
            throw new ProviderException('The rerank response has no results.');
        }

        $ranked = [];
        foreach ($response['results'] as $item) {
            $index = $item['index'] ?? null;
            $score = $item['relevance_score'] ?? null;

            if (!is_int($index) || !isset($documents[$index])) {
                throw new ProviderException('The rerank response names document ' . json_encode($index) . ', which was not sent.');
            }

            if (isset($ranked[$index])) {
                throw new ProviderException("The rerank response names document {$index} twice.");
            }

            if (!is_int($score) && !is_float($score)) {
                throw new ProviderException("The rerank response gives document {$index} a score that is not a number.");
            }

            $ranked[$index] = $documents[$index]->setScore($score);
        }

        return array_values($ranked);
    }
}
