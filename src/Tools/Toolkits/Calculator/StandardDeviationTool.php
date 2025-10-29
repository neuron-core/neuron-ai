<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class StandardDeviationTool extends Tool
{
    public function __construct(protected int $precision = 2, protected bool $sample = true)
    {
        parent::__construct(
            'calculate_standard_deviation',
            <<<DESC
Calculates the standard deviation, which measures how spread out the data points are from the mean.
A low standard deviation indicates data points are close to the mean, while a high standard deviation
indicates greater variability. Use this tool for risk assessment, quality control, measuring consistency,
or understanding data distribution. Choose sample (n-1) for sample data or population (n) for complete populations.
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

        if (\count($numericData) === 1 && $this->sample) {
            return ['error' => 'Cannot calculate sample standard deviation with only one data point'];
        }

        // Convert to float values
        $numericData = \array_map(floatval(...), $numericData);

        // Calculate mean
        $mean = \array_sum($numericData) / \count($numericData);

        // Calculate the sum of squared differences
        $sumSquaredDifferences = 0;
        foreach ($numericData as $value) {
            $sumSquaredDifferences += ($value - $mean) ** 2;
        }

        // Calculate variance
        $divisor = $this->sample ? \count($numericData) - 1 : \count($numericData);
        $variance = $sumSquaredDifferences / $divisor;

        // Calculate standard deviation
        $standardDeviation = \sqrt($variance);

        return \round($standardDeviation, $this->precision);
    }
}
