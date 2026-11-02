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
    /**
     * @param string|null $assertion The assertion's getName(), whatever the label.
     * @param string $message What the assertion said, passing or failing: a judge's reasoning.
     * @param int|null $line Where the evaluator asserted.
     */
    public function __construct(
        public readonly string $label,
        public readonly float $value,
        public readonly bool $passed,
        public readonly ?string $assertion = null,
        public readonly string $message = '',
        public readonly ?int $line = null,
    ) {
    }
}
