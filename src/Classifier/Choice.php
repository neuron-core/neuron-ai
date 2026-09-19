<?php

declare(strict_types=1);

namespace NeuronAI\Classifier;

use InvalidArgumentException;

use function count;
use function is_string;
use function trim;

class Choice
{
    /**
     * @param array<string, string> $options Mutually exclusive option identifiers and descriptions.
     */
    public function __construct(
        public readonly string $instructions,
        public readonly array $options,
    ) {
        if (trim($instructions) === '') {
            throw new InvalidArgumentException('Choice instructions cannot be empty.');
        }

        if (count($options) < 2) {
            throw new InvalidArgumentException('A choice requires at least two options.');
        }

        foreach ($options as $id => $description) {
            if (!is_string($id) || trim($id) === '' || !is_string($description) || trim($description) === '') {
                throw new InvalidArgumentException('Choice options require non-empty string identifiers and descriptions.');
            }
        }
    }
}
