<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Stub;

use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Assertions\AbstractAssertion;

/**
 * Test assertion that returns a predetermined result, pass or fail
 */
class FixedResultAssertion extends AbstractAssertion
{
    public function __construct(protected readonly AssertionResult $result)
    {
    }

    public function evaluate(mixed $actual): AssertionResult
    {
        return $this->result;
    }
}
