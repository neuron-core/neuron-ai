<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function array_count_values;
use function array_filter;
use function array_keys;
use function array_map;
use function max;
use function sort;

class ModeTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'calculate_mode',
            <<<DESC
Finds the mode(s) - the most frequently occurring value(s) in a dataset.
Returns all values that appear with the highest frequency. Use this tool to identify
the most common values, analyze categorical data converted to numbers, find typical
responses in surveys, or detect patterns in discrete data. Can return multiple modes
if several values tie for highest frequency.
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

    public function __invoke(array $numbers): array
    {
        // Validate input
        if ($numbers === []) {
            return ['error' => 'Data array cannot be empty'];
        }

        // Filter and validate numeric values
        $numericData = array_filter($numbers, \is_numeric(...));

        if ($numericData === []) {
            return ['error' => 'Data array must contain at least one numeric value'];
        }

        // Convert to float values
        $numericData = array_map(floatval(...), $numericData);

        // Count frequency of each value (convert to strings for array_count_values)
        $stringData = array_map(strval(...), $numericData);
        $frequencies = array_count_values($stringData);
        $maxFrequency = max($frequencies);

        // Find all values with maximum frequency
        $modes = array_keys($frequencies, $maxFrequency);

        // Convert back to numeric values and sort
        $modes = array_map(floatval(...), $modes);
        sort($modes);

        return $modes;
    }
}
