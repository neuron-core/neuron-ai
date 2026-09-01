<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Stub;

use NeuronAI\Evaluation\Assertions\AbstractAssertion;
use NeuronAI\Evaluation\AssertionResult;

class TestableAbstractAssertion extends AbstractAssertion
{
    public function evaluate(mixed $actual): AssertionResult
    {
        return AssertionResult::pass(1.0, 'test evaluation');
    }
}
