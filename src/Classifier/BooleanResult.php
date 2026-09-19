<?php

declare(strict_types=1);

namespace NeuronAI\Classifier;

use InvalidArgumentException;

use function is_finite;

class BooleanResult
{
    /**
     * @param float $probability Probability that the proposition is true; false has probability 1 - p.
     */
    public function __construct(
        public readonly Boolean $definition,
        public readonly float $probability,
    ) {
        if (!is_finite($probability) || $probability < 0 || $probability > 1) {
            throw new InvalidArgumentException('Boolean probability must be finite and between zero and one.');
        }
    }
}
