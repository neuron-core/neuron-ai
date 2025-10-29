<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class VarianceTool extends Tool
{
    public function __construct(protected int $precision = 2, protected bool $sample = true)
    {
        parent::__construct(
            'calculate_variance',
            <<<DESC
Calculates the variance, which measures the average squared deviation from the mean.
Variance quantifies how much the data points differ from the average value.
Use this tool for statistical analysis, understanding data spread, portfolio risk analysis,
or quality control measurements. The square root of variance gives the standard deviation.
Choose sample (n-1) for sample data or population (n) for complete populations.
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
            return ['error' => 'Cannot calculate sample variance with only one data point'];
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

        return \round($variance, $this->precision);
    }
}
