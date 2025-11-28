<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function bcmul;

class FactorialTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'calculate_factorial',
            <<<DESC
Calculates the factorial of a non-negative integer. Factorial (n!) is the product of all positive
integers from 1 to n. For example, 5! = 5 × 4 × 3 × 2 × 1 = 120. Use this tool for combinatorics problems,
probability calculations, permutations and combinations, mathematical series, and statistical computations.
The input must be a non-negative integer (0, 1, 2, 3, etc.). Note that 0! = 1 by mathematical convention.
DESC
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'number',
                type: PropertyType::INTEGER,
                description: 'The non-negative integer to calculate the factorial of (must be ≥ 0)',
                required: true
            ),
        ];
    }

    public function __invoke(int $number): int|float|array
    {
        // Validate input
        if ($number < 0) {
            return ['error' => 'Factorial is not defined for negative numbers.'];
        }

        // Handle edge cases
        if ($number === 0 || $number === 1) {
            return 1;
        }

        // For larger numbers, use BCMath to handle arbitrary precision
        if ($number > 20) {
            return self::calculateWithBCMath($number);
        }

        // For smaller numbers, use regular integer calculation
        $result = 1;
        for ($i = 2; $i <= $number; $i++) {
            $result *= $i;
        }

        return $result;
    }

    /**
     * Calculate factorial using BCMath for large numbers
     */
    private function calculateWithBCMath(int $number): float
    {
        $result = '1';

        for ($i = 2; $i <= $number; $i++) {
            $result = bcmul($result, (string)$i);
        }

        return (float)$result;
    }
}
