<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class NthRootTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'calculate_nth_root',
            <<<DESC
                Calculates the nth root of a number (e.g., square root, cube root, fourth root, etc.). Use this tool when you need to
                find what number, when raised to the power of n, equals the given input. For example,
                the 3rd root (cube root) of 8 is 2, because 2³ = 8. This tool handles any positive root
                degree and works with both positive and negative numbers (though negative numbers only work with odd roots).
                Common use cases include volume calculations (cube roots), higher-order geometric problems, financial calculations
                involving compound growth rates, and advanced mathematical computations.
                DESC
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'number',
                type: PropertyType::NUMBER,
                description: 'The number to calculate the nth root of',
                required: true,
            ),
            new ToolProperty(
                name: 'root_degree',
                type: PropertyType::INTEGER,
                description: 'The degree of the root (e.g., 2 for square root, 3 for cube root, 4 for fourth root, etc.). Must be a positive number.',
                required: true,
            ),
        ];
    }

    public function __invoke(float|int $number, int $root_degree): float|int
    {
        return $number ** (1 / $root_degree);
    }
}
