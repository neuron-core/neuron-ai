<?php

declare(strict_types=1);

namespace NeuronAI\Classifier;

use InvalidArgumentException;

use function trim;

class Boolean
{
    public function __construct(public readonly string $instructions)
    {
        if (trim($instructions) === '') {
            throw new InvalidArgumentException('Boolean instructions cannot be empty.');
        }
    }
}
