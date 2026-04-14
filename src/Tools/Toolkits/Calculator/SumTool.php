<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class SumTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'sum',
            description: 'Calculate the sum between two numbers and return the result',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'number1',
                type: PropertyType::NUMBER,
                description: 'First addend',
                required: true,
            ),
            ToolProperty::make(
                name: 'number2',
                type: PropertyType::NUMBER,
                description: 'Second addend',
                required: true,
            ),
        ];
    }

    public function __invoke(int|float $number1, int|float $number2): int|float
    {
        return $number1 + $number2;
    }
}
