<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function array_map;
use function array_sum;
use function count;

/**
 * Base of the tools that describe a dataset: they share the `numbers` input,
 * its validation and the moments the spread measures build on.
 */
abstract class StatisticTool extends Tool
{
    protected function properties(): array
    {
        return [
            new ArrayProperty(
                name: 'numbers',
                description: 'The dataset as a list of numbers',
                required: true,
                items: new ToolProperty('number', PropertyType::NUMBER, 'A numerical value', true),
                minItems: 1,
            ),
        ];
    }

    /**
     * The feedback for an empty dataset, null when there is data.
     */
    protected function invalidDataset(array $numbers): ?ToolOutput
    {
        return $numbers === [] ? ToolOutput::error('The dataset cannot be empty.') : null;
    }

    /**
     * Like invalidDataset(), also refusing a single value when the dataset is a sample:
     * the n - 1 denominator would be zero.
     */
    protected function invalidSample(array $numbers, bool $population): ?ToolOutput
    {
        if (!$population && count($numbers) === 1) {
            return ToolOutput::error('A sample needs at least two values; set population to true if this single value is the whole population.');
        }

        return $this->invalidDataset($numbers);
    }

    /**
     * @param array<int|float> $numbers
     */
    protected function mean(array $numbers): int|float
    {
        return array_sum($numbers) / count($numbers);
    }

    /**
     * @param array<int|float> $numbers
     */
    protected function variance(array $numbers, bool $population): int|float
    {
        $mean = $this->mean($numbers);
        $squaredDeviations = array_sum(array_map(fn (int|float $value): int|float => ($value - $mean) ** 2, $numbers));

        return $squaredDeviations / (count($numbers) - ($population ? 0 : 1));
    }
}
