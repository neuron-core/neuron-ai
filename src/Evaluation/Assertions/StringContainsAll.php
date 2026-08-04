<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

use function implode;
use function is_string;
use function str_contains;
use function strtolower;

class StringContainsAll extends StringAssertion
{
    /**
     * @param string[] $keywords
     */
    public function __construct(protected array $keywords)
    {
    }

    protected function evaluateString(string $actual): AssertionResult
    {
        $lowerHaystack = strtolower($actual);
        $missing = [];

        foreach ($this->keywords as $keyword) {
            if (!is_string($keyword)) {
                continue;
            }

            if (!str_contains($lowerHaystack, strtolower($keyword))) {
                $missing[] = $keyword;
            }
        }

        if ($missing === []) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            "Expected '{$actual}' to contain all keywords. Missing: " . implode(', ', $missing),
        );
    }
}
