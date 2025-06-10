<?php

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class SubtractTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'subtract',
            'Calculate the subtraction between two numbers and return the result',
        );

        $this->addProperty(
            new ToolProperty(
                'a',
                PropertyType::NUMBER,
                'The first number of the subtraction.',
                true
            )
        )->addProperty(
            new ToolProperty(
                'b',
                PropertyType::NUMBER,
                'The second number of the subtraction.',
                true
            )
        )->setCallable(
            fn (int|float $a, int|float $b) => ['operation' => $this->name, 'result' => $a - $b]
        );
    }
}
