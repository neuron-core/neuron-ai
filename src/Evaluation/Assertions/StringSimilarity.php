<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorSimilarity;

class StringSimilarity extends StringAssertion
{
    public function __construct(
        protected string $reference,
        protected EmbeddingsProviderInterface $embeddingsProvider,
        protected float $threshold = 0.6
    ) {
    }

    /**
     * @throws VectorStoreException
     */
    protected function evaluateString(string $actual): AssertionResult
    {
        $actualEmbeddings = $this->embeddingsProvider->embedText($actual);
        $referenceEmbeddings = $this->embeddingsProvider->embedText($this->reference);

        $score = VectorSimilarity::cosineSimilarity($actualEmbeddings, $referenceEmbeddings);

        if ($score >= $this->threshold) {
            return AssertionResult::pass($score);
        }

        return AssertionResult::fail(
            $score,
            "Expected '{$actual}' to be similar to '{$this->reference}' (threshold: '{$this->threshold}')",
        );
    }
}
