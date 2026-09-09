<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function bcmul;

class FactorialTool extends IntegerTool
{
    protected const MAX_INPUT = 1000;

    protected string $name = 'factorial';

    protected ?string $description = <<<DESC
        Calculate n! exactly, as an integer of any size, for permutations, probability and series
        that need the exact value. n must be an integer between 0 and 1000.
        DESC;

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'n',
                type: PropertyType::INTEGER,
                description: 'A non-negative integer',
                required: true,
            ),
        ];
    }

    public function __invoke(int $n): string|ToolOutput
    {
        if ($n < 0 || $n > self::MAX_INPUT) {
            return ToolOutput::error('The factorial is available for integers between 0 and ' . self::MAX_INPUT . '.');
        }

        $factorial = '1';

        for ($factor = 2; $factor <= $n; $factor++) {
            $factorial = bcmul($factorial, (string) $factor);
        }

        return $factorial;
    }
}
