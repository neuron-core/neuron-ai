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
        return "This toolkit allows you to perform mathematical operations. You can also use this functions to solve
        mathematical expressions executing smaller operations step by step to calculate the final result.";
    }

    public function provide(): array
    {
        return [
            SumTool::make(),
            SubtractTool::make(),
            MultiplyTool::make(),
            DivideTool::make(),
            ExponentialTool::make(),
            SquareRootTool::make(),
            NthRootTool::make(),
            MeanTool::make(),
            MedianTool::make(),
            ModeTool::make(),
            StandardDeviationTool::make(),
            VarianceTool::make(),
        ];
    }

}
