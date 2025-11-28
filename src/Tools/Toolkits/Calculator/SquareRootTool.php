<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function sqrt;

class SquareRootTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'calculate_square_root',
            description: <<<DESC
Calculates the square root of a positive number. Use this tool when you need to find the square root
of any positive number, whether it's for mathematical calculations, geometric problems, or statistical computations.
The input must be a non-negative number (zero or positive).
This tool is particularly useful for calculations involving areas, distances, standard deviations,
or any scenario where you need to find what number, when multiplied by itself, equals the given input.
DESC
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'number',
                type: PropertyType::NUMBER,
                description: 'The positive number to calculate the square root of',
                required: true,
            )
        ];
    }

    public function __invoke(float|int $number): float|int
    {
        return sqrt($number);
    }
}
