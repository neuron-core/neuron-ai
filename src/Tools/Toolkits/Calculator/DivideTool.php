<?php

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class DivideTool extends Tool
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
        )->setCallable(function (int|float $a, int|float $b) {
            if ($b === 0) {
                return ['operation' => 'division', 'error' => 'Division by zero is not allowed.'];
            }
            return $a / $b;
        });
    }
}
