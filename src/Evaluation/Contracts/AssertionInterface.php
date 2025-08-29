<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Contracts;

use NeuronAI\Evaluation\AssertionResult;

interface AssertionInterface
{
    /**
     * Evaluate the given input against expected criteria
     */
    public function evaluate(mixed $actual): AssertionResult;

    /**
     * Get the name of this evaluation rule
     */
    public function getName(): string;
}
