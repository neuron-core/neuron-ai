<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use InvalidArgumentException;
use NeuronAI\Evaluation\Contracts\AssertionInterface;
use ReflectionClass;

use function array_values;
use function get_debug_type;
use function is_finite;
use function is_string;

abstract class AbstractAssertion implements AssertionInterface
{
    public function getName(): string
    {
        return (new ReflectionClass($this))->getShortName();
    }

    /**
     * Scores are compared with >=, so a threshold outside [0, 1] would make
     * an assertion that can never pass, or one that always does.
     *
     * @throws InvalidArgumentException
     */
    protected function validateThreshold(float $threshold): void
    {
        if (!is_finite($threshold) || $threshold < 0 || $threshold > 1) {
            throw new InvalidArgumentException('Threshold must be finite and between zero and one.');
        }
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     * @throws InvalidArgumentException
     */
    protected function stringList(array $values, string $role): array
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException("{$this->getName()} {$role} must be strings, got " . get_debug_type($value));
            }
        }

        return array_values($values);
    }
}
