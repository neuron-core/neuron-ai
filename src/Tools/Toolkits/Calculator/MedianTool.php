<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class MedianTool extends Tool
{
    public function __construct(protected int $precision = 2)
    {
        parent::__construct(
            'calculate_median',
            <<<DESC
Calculates the median (middle value) of a dataset when sorted in ascending order.
For datasets with an even number of values, returns the average of the two middle values.
The median is less affected by outliers than the mean, making it useful for skewed distributions,
income analysis, or when you need a robust measure of central tendency.
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

        // Convert to float values and sort
        $numericData = \array_map(floatval(...), $numericData);
        \sort($numericData);

        $count = \count($numericData);
        $middle = (int) \floor($count / 2);

        if ($count % 2 === 0) {
            // Even number of elements - average of two middle values
            $median = ($numericData[$middle - 1] + $numericData[$middle]) / 2;
        } else {
            // Odd number of elements - middle value
            $median = $numericData[$middle];
        }

        return \round($median, $this->precision);
    }
}
