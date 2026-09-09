<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function bcdiv;
use function bcmul;
use function min;

class CombinationsTool extends IntegerTool
{
    protected const MAX_TERMS = 1000;

    protected string $name = 'combinations';

    protected ?string $description = <<<DESC
        Calculate the binomial coefficient C(n, k): the exact number of ways to choose k items
        out of n when order does not matter, as an integer of any size.
        DESC;

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'n',
                type: PropertyType::INTEGER,
                description: 'The number of items to choose from',
                required: true,
            ),
            ToolProperty::make(
                name: 'k',
                type: PropertyType::INTEGER,
                description: 'The number of items to choose',
                required: true,
            ),
        ];
    }

    public function __invoke(int $n, int $k): string|ToolOutput
    {
        if ($n < 0 || $k < 0 || $k > $n) {
            return ToolOutput::error('Combinations require 0 <= k <= n.');
        }

        $k = min($k, $n - $k);

        if ($k > self::MAX_TERMS) {
            return ToolOutput::error('The smaller of k and n - k must not exceed ' . self::MAX_TERMS . '.');
        }

        // Each step yields C(n - k + i, i), an integer, so every division is exact
        $combinations = '1';

        for ($i = 1; $i <= $k; $i++) {
            $combinations = bcdiv(bcmul($combinations, (string) ($n - $k + $i)), (string) $i);
        }

        return $combinations;
    }
}
