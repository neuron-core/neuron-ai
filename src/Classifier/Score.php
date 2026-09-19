<?php

declare(strict_types=1);

namespace NeuronAI\Classifier;

use InvalidArgumentException;

use function count;
use function is_string;
use function trim;

class Score
{
    /**
     * @param list<string> $levels Descriptions in ascending order, at equally spaced positions starting at zero.
     */
    public function __construct(
        public readonly string $instructions,
        public readonly array $levels,
    ) {
        if (trim($instructions) === '') {
            throw new InvalidArgumentException('Score instructions cannot be empty.');
        }

        foreach ($levels as $description) {
            if (!is_string($description) || trim($description) === '') {
                throw new InvalidArgumentException('Score levels require non-empty string descriptions.');
            }
        }

        if (count($levels) < 2) {
            throw new InvalidArgumentException('A score requires a list of at least two ordered levels.');
        }
    }
}
