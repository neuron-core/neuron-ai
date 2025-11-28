<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function floatval;

class DivideTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'divide',
            'Calculate the division between two numbers and return the result'
        );
    }

    public function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'number1',
                type: PropertyType::NUMBER,
                description: 'The numerator of the division',
                required: true,
            ),
            ToolProperty::make(
                name: 'number2',
                type: PropertyType::NUMBER,
                description: 'The denominator of the division',
                required: true,
            )
        ];
    }

    public function __invoke(int|float $number1, int|float $number2): int|float|array
    {
        if (floatval($number2) === 0.0) {
            return [
                'operation' => $this->name,
                'error' => 'Division by zero is not allowed.'
            ];
        }

        return $number1 / $number2;
    }
}
