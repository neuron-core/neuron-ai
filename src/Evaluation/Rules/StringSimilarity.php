<?php

namespace NeuronAI\Evaluation\Rules;

use NeuronAI\Evaluation\EvaluationRuleResult;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorSimilarity;

class StringSimilarity extends AbstractRule
{
    public function __construct(
        protected string $reference,
        protected EmbeddingsProviderInterface $embeddingsProvider,
        protected float $threshold = 0.6
    ){
    }

    /**
     * @throws VectorStoreException
     */
    public function evaluate(mixed $actual): EvaluationRuleResult
    {
        if (!\is_string($actual)) {
            return EvaluationRuleResult::fail(
                0.0,
                'Expected actual value to be a string, got ' . \gettype($actual),
            );
        }

        $actualEmbeddings = $this->embeddingsProvider->embedText($actual);
        $referenceEmbeddings = $this->embeddingsProvider->embedText($this->reference);

        $score = VectorSimilarity::cosineDistance($actualEmbeddings, $referenceEmbeddings);

        if ($score >= $this->threshold) {
            return EvaluationRuleResult::pass($score);
        }

        return EvaluationRuleResult::fail(
            $score,
            "Expected '{$actual}' to be similar to '{$this->reference}' (threshold: '{$this->threshold}')",
        );
    }
}
