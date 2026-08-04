<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

/**
 * Immutable value object representing a single labeled assertion score.
 *
 * The label defaults to the assertion's getName() and can be overridden
 * per call via BaseEvaluator::assert($rule, $actual, label: '...') to
 * aggregate scores under a shared metric name (e.g. "task_completion").
 */
class Score
{
    public function __construct(
        public readonly string $label,
        public readonly float $value,
        public readonly bool $passed,
    ) {
    }
}
