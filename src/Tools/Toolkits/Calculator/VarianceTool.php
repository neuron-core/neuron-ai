<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

class VarianceTool extends StatisticTool
{
    protected string $name = 'variance';

    protected ?string $description = <<<DESC
        Calculate the variance of a dataset, the mean squared deviation from the mean: the sample
        variance dividing by n - 1 by default, or the population variance dividing by n when
        population is true.
        DESC;

    protected function properties(): array
    {
        return [
            ...parent::properties(),
            ToolProperty::make(
                name: 'population',
                type: PropertyType::BOOLEAN,
                description: 'True when the dataset is the entire population rather than a sample. Defaults to false.',
            ),
        ];
    }

    public function __invoke(array $numbers, ?bool $population = null): string|ToolOutput
    {
        $population ??= false;

        return $this->invalidSample($numbers, $population) ?? Number::format($this->variance($numbers, $population));
    }
}
