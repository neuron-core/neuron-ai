<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\ToolOutput;

use function count;
use function intdiv;
use function sort;

class MedianTool extends StatisticTool
{
    protected string $name = 'median';

    protected ?string $description = <<<DESC
        Calculate the median of a dataset: the middle value once sorted, or the mean of the two
        middle values when the count is even. Less sensitive to outliers than the mean.
        DESC;

    public function __invoke(array $numbers): string|ToolOutput
    {
        return $this->invalidDataset($numbers) ?? Number::format($this->median($numbers));
    }

    /**
     * @param array<int|float> $numbers
     */
    protected function median(array $numbers): int|float
    {
        sort($numbers);

        $count = count($numbers);
        $middle = intdiv($count, 2);

        return $count % 2 === 0
            ? ($numbers[$middle - 1] + $numbers[$middle]) / 2
            : $numbers[$middle];
    }
}
