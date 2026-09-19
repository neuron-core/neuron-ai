<?php

declare(strict_types=1);

namespace NeuronAI\Classifier;

use InvalidArgumentException;

use function array_diff_key;
use function count;

class ScoreResult
{
    public readonly float $score;
    public readonly ProbabilityDistribution $distribution;

    /**
     * @param array<int, int|float> $probabilities Probability for every zero-based level index.
     */
    public function __construct(
        public readonly Score $definition,
        array $probabilities,
    ) {
        if (count($probabilities) !== count($definition->levels)
            || array_diff_key($definition->levels, $probabilities) !== []) {
            throw new InvalidArgumentException('Score probabilities must cover exactly the defined levels.');
        }

        $this->distribution = new ProbabilityDistribution($probabilities);
        $score = 0.0;

        foreach ($this->distribution->probabilities as $level => $probability) {
            $score += $level * $probability;
        }

        $this->score = $score;
    }
}
