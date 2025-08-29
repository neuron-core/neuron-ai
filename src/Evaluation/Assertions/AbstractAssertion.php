<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Evaluation\Contracts\AssertionInterface;

abstract class AbstractAssertion implements AssertionInterface
{
    public function getName(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }
}
