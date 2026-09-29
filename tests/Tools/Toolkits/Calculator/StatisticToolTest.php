<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\MeanTool;
use NeuronAI\Tools\Toolkits\Calculator\MedianTool;
use NeuronAI\Tools\Toolkits\Calculator\StandardDeviationTool;
use NeuronAI\Tools\Toolkits\Calculator\StatisticTool;
use NeuronAI\Tools\Toolkits\Calculator\VarianceTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatisticToolTest extends TestCase
{
    use ToolErrorAssertions;

    /**
     * @return array<string, array{StatisticTool, array<int|float>}>
     */
    public static function overflows(): array
    {
        return [
            'mean' => [new MeanTool(), [1e308, 1e308]],
            'median' => [new MedianTool(), [1e308, 1.7e308]],
            'variance' => [new VarianceTool(), [-1e200, 1e200]],
            'standard deviation' => [new StandardDeviationTool(), [-1e200, 1e200]],
        ];
    }

    /**
     * @param array<int|float> $numbers
     */
    #[DataProvider('overflows')]
    public function test_an_intermediate_overflow_is_an_error_not_inf(StatisticTool $tool, array $numbers): void
    {
        $this->assertToolError('The computation overflows the range of a double.', $tool($numbers));
    }
}
