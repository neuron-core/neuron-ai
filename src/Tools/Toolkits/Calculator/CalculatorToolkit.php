<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make()
 */
class CalculatorToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return <<<TEXT
            This toolkit performs mathematical calculations with precision and determinism.
            For arithmetic, algebra, trigonometry, logarithms or any formula, write the whole expression
            and pass it to the evaluate tool in a single call instead of computing intermediate steps
            yourself; it works in double precision, about 15 significant digits. Use the integer tools
            (factorial, combinations, permutations, gcd, lcm, mod_pow, is_prime, prime_factors) when an
            exact result with large integers is required, and the statistics tools for datasets.
            TEXT;
    }

    public function provide(): array
    {
        return [
            EvaluateTool::make(),
            FactorialTool::make(),
            CombinationsTool::make(),
            PermutationsTool::make(),
            GcdTool::make(),
            LcmTool::make(),
            ModPowTool::make(),
            IsPrimeTool::make(),
            PrimeFactorsTool::make(),
            MeanTool::make(),
            MedianTool::make(),
            ModeTool::make(),
            VarianceTool::make(),
            StandardDeviationTool::make(),
        ];
    }
}
