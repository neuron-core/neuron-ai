<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Exceptions\NeuronException;

use function sprintf;

/**
 * A malformed or mathematically undefined expression. The message is written
 * for the model and points at the 1-based position of the problem in the source.
 */
class ExpressionException extends NeuronException
{
    public static function at(string $problem, int $offset): self
    {
        return new self(sprintf('%s at position %d', $problem, $offset + 1));
    }
}
