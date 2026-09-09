<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\ToolOutput;

use function sqrt;

class StandardDeviationTool extends VarianceTool
{
    protected string $name = 'standard_deviation';

    protected ?string $description = <<<DESC
        Calculate the standard deviation of a dataset, the square root of its variance: the sample
        standard deviation dividing by n - 1 by default, or the population one dividing by n when
        population is true.
        DESC;

    public function __invoke(array $numbers, ?bool $population = null): string|ToolOutput
    {
        $population ??= false;

        return $this->invalidSample($numbers, $population) ?? Number::format(sqrt($this->variance($numbers, $population)));
    }
}
