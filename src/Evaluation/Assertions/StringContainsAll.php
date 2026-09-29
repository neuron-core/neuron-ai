<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\AssertionResult;

use function implode;
use function str_contains;

class StringContainsAll extends StringAssertion
{
    /**
     * @param string[] $keywords
     */
    public function __construct(protected array $keywords)
    {
        $this->keywords = $this->stringList($keywords, 'keywords');
    }

    protected function evaluateString(string $actual): AssertionResult
    {
        $haystack = $this->foldCase($actual);
        $missing = [];

        foreach ($this->keywords as $keyword) {
            if (!str_contains($haystack, $this->foldCase($keyword))) {
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
