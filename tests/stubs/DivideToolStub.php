<?php

namespace NeuronAI\Tests\stubs;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class DivideToolStub extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'divide_numbers',
            'Divide first number by second and return the result.',
        );

        $this->addProperty(
            new ToolProperty(
                'a',
                PropertyType::NUMBER,
                'The numerator of the division.',
                true
            )
        )->addProperty(
            new ToolProperty(
                'b',
                PropertyType::NUMBER,
                'The denominator of the division.',
                true
            )
        )->setCallable($this);
    }

    public function __invoke(int|float $a, int|float $b): array|int|float
    {
        if ($b === 0) {
            return ['operation' => 'division', 'error' => 'Division by zero is not allowed.'];
        }
        return $a / $b;
    }
}
