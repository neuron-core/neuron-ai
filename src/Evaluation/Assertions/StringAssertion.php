<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use InvalidArgumentException;
use NeuronAI\Evaluation\AssertionResult;

use function get_debug_type;
use function is_string;
use function mb_convert_case;

use const MB_CASE_FOLD;

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

    /**
     * Unicode case folding: the comparison form for case-insensitive matching
     * in every script, including ß against "ss".
     */
    protected function foldCase(string $text): string
    {
        return mb_convert_case($text, MB_CASE_FOLD, 'UTF-8');
    }
}
