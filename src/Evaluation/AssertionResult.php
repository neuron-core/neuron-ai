<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

class AssertionResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly float $score,
        public readonly string $message,
        public readonly array $context = []
    ) {
    }

    /**
     * Create a successful result
     */
    public static function pass(float $score, string $message = '', array $context = []): self
    {
        return new self(true, $score, $message, $context);
    }

    /**
     * Create a failed result
     */
    public static function fail(float $score, string $message, array $context = []): self
    {
        return new self(false, $score, $message, $context);
    }
}
