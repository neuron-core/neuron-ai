<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

use InvalidArgumentException;

use function is_finite;

class AssertionResult
{
    /**
     * @throws InvalidArgumentException A non-finite score would corrupt every
     *         aggregate it enters and cannot be written to the JSON report.
     */
    public function __construct(
        public readonly bool $passed,
        public readonly float $score,
        public readonly string $message,
        public readonly array $context = []
    ) {
        if (!is_finite($score)) {
            throw new InvalidArgumentException("An assertion score must be a finite number, got {$score}.");
        }
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
