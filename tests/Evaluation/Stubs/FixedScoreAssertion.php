<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stubs;

use NeuronAI\Evaluation\Assertions\AbstractAssertion;
use NeuronAI\Evaluation\AssertionResult;

/**
 * Test assertion that returns a fixed score
 */
class FixedScoreAssertion extends AbstractAssertion
{
    public function __construct(private readonly float $score)
    {
    }

    public function evaluate(mixed $actual): AssertionResult
    {
        return AssertionResult::pass($this->score);
    }
}
