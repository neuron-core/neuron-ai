<?php

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class ExponentiateTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'exponentiate',
            'Raise first number to the power of the second number and return the result.'
        );

        $this->addProperty(
            new ToolProperty(
                'a',
                PropertyType::NUMBER,
                'Base.',
                true
            )
        )->addProperty(
            new ToolProperty(
                'b',
                PropertyType::NUMBER,
                'Exponential.',
                true
            )
        )->setCallable(
            fn (int|float $a, int|float $b) => ['operation' => $this->name, 'result' => pow($a, $b)]
        );
    }
}
