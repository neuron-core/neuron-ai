<?php

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class MultiplyTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'multiply',
            'Multiply two numbers and return the result.',
        );

        $this->addProperty(
            new ToolProperty(
                'a',
                PropertyType::NUMBER,
                'First number.',
                true
            )
        )->addProperty(
            new ToolProperty(
                'b',
                PropertyType::NUMBER,
                'Second number.',
                true
            )
        )->setCallable(
            fn (int|float $a, int|float $b) => ['operation' => $this->name, 'result' => $a * $b]
        );
    }
}
