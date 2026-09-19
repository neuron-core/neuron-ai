<?php

declare(strict_types=1);

namespace NeuronAI\Classifier;

use InvalidArgumentException;

use function abs;
use function array_sum;
use function count;
use function is_finite;
use function is_float;
use function is_int;

class ProbabilityDistribution
{
    protected const SUM_TOLERANCE = 1e-6;

    /** @var array<array-key, float> */
    public readonly array $probabilities;

    /**
     * @param array<array-key, int|float> $probabilities Complete distribution, summing to one within 1e-6.
     */
    public function __construct(array $probabilities)
    {
        if (count($probabilities) < 2) {
            throw new InvalidArgumentException('A probability distribution requires at least two outcomes.');
        }

        foreach ($probabilities as $probability) {
            if ((!is_int($probability) && !is_float($probability))
                || !is_finite($probability) || $probability < 0 || $probability > 1) {
                throw new InvalidArgumentException('Probabilities must be finite numbers between zero and one.');
            }
        }

        $total = array_sum($probabilities);

        if (abs($total - 1.0) > self::SUM_TOLERANCE) {
            throw new InvalidArgumentException('Probabilities must sum to one.');
        }

        // Remove accepted rounding error so derived scores remain within their scale.
        foreach ($probabilities as $id => $probability) {
            $probabilities[$id] = $probability / $total;
        }

        $this->probabilities = $probabilities;
    }
}
