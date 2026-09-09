<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\ToolOutput;

class MeanTool extends StatisticTool
{
    protected string $name = 'mean';

    protected ?string $description = 'Calculate the arithmetic mean (average) of a dataset.';

    public function __invoke(array $numbers): string|ToolOutput
    {
        return $this->invalidDataset($numbers) ?? Number::format($this->mean($numbers));
    }
}
