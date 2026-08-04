<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use InvalidArgumentException;
use NeuronAI\Evaluation\AssertionResult;

use function get_debug_type;
use function is_string;

/**
 * Base for assertions that evaluate a string output.
 */
abstract class StringAssertion extends AbstractAssertion
{
    /**
     * The interface's `mixed` cannot be narrowed to `string` (parameter types
     * are contravariant), so the type is enforced here: anything else is a
     * coding error in the evaluator — an exception the runner records as an
     * item error — not a failed assertion about the agent.
     */
    final public function evaluate(mixed $actual): AssertionResult
    {
        if (!is_string($actual)) {
            throw new InvalidArgumentException(
                static::class . ' evaluates a string, got ' . get_debug_type($actual)
            );
        }

        return $this->evaluateString($actual);
    }

    abstract protected function evaluateString(string $actual): AssertionResult;
}
