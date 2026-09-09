<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function bcmul;

class PermutationsTool extends IntegerTool
{
    protected const MAX_TERMS = 1000;

    protected string $name = 'permutations';

    protected ?string $description = <<<DESC
        Calculate P(n, k) = n! / (n - k)!: the exact number of ways to arrange k items chosen
        out of n when order matters, as an integer of any size.
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
                description: 'The number of items to arrange',
                required: true,
            ),
        ];
    }

    public function __invoke(int $n, int $k): string|ToolOutput
    {
        if ($n < 0 || $k < 0 || $k > $n) {
            return ToolOutput::error('Permutations require 0 <= k <= n.');
        }

        if ($k > self::MAX_TERMS) {
            return ToolOutput::error('k must not exceed ' . self::MAX_TERMS . '.');
        }

        $permutations = '1';

        for ($factor = $n - $k + 1; $factor <= $n; $factor++) {
            $permutations = bcmul($permutations, (string) $factor);
        }

        return $permutations;
    }
}
