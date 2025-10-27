<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class MeanTool extends Tool
{
    public function __construct(protected int $precision = 2)
    {
        parent::__construct(
            'calculate_mean',
            <<<DESC
Calculates the arithmetic mean (average) of a dataset. The mean is the sum of all values divided
by the number of values. Use this tool when you need the central tendency of numerical data,
analyzing performance metrics, calculating average scores, or determining typical values in a
dataset. Input should be an array of numbers.
DESC
        );
    }

    protected function properties(): array
    {
        return [
            new ArrayProperty(
                name: 'numbers',
                description: 'Array of numerical values',
                required: true,
                items: new ToolProperty(
                    'number',
                    PropertyType::NUMBER,
                    'A numerical value',
                    true,
                )
            ),
        ];
    }

    public function __invoke(array $numbers): float|array
    {
        // Validate input
        if ($numbers === []) {
            return ['error' => 'Data array cannot be empty'];
        }

        // Filter and validate numeric values
        $numericData = \array_filter($numbers, \is_numeric(...));

        if ($numericData === []) {
            return ['error' => 'Data array must contain at least one numeric value'];
        }

        // Convert to float values
        $numericData = \array_map(floatval(...), $numericData);
        $mean = \array_sum($numericData) / \count($numericData);

        return \round($mean, $this->precision);
    }
}
