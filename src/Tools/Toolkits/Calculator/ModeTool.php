<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\ToolOutput;

use function array_count_values;
use function array_keys;
use function array_map;
use function implode;
use function max;
use function sort;

use const SORT_NUMERIC;

class ModeTool extends StatisticTool
{
    protected string $name = 'mode';

    protected ?string $description = <<<DESC
        Find the mode of a dataset: its most frequent value. Returns every value tied for the
        highest frequency, comma separated in ascending order.
        DESC;

    public function __invoke(array $numbers): string|ToolOutput
    {
        return $this->invalidDataset($numbers) ?? $this->modes($numbers);
    }

    /**
     * @param array<int|float> $numbers
     */
    protected function modes(array $numbers): string
    {
        $frequencies = array_count_values(array_map(Number::format(...), $numbers));
        $modes = array_keys($frequencies, max($frequencies), true);
        sort($modes, SORT_NUMERIC);

        return implode(', ', $modes);
    }
}
