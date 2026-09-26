<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tools\Toolkits\Calculator\MeanTool;
use NeuronAI\Tools\Toolkits\Calculator\MedianTool;
use NeuronAI\Tools\Toolkits\Calculator\StandardDeviationTool;
use NeuronAI\Tools\Toolkits\Calculator\VarianceTool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function var_export;

class StatisticOverflowTest extends TestCase
{
    /**
     * @return array<string, array{callable(): (string|ToolOutput)}>
     */
    public static function overflowingStatistics(): array
    {
        return [
            'mean of huge values' => [static fn (): string|ToolOutput => (new MeanTool())([1e308, 1e308])],
            'median of huge even pair' => [static fn (): string|ToolOutput => (new MedianTool())([1.7e308, 1.7e308])],
            'variance of opposite huge values' => [static fn (): string|ToolOutput => (new VarianceTool())([1e308, -1e308])],
            'standard deviation of opposite huge values' => [static fn (): string|ToolOutput => (new StandardDeviationTool())([1e308, -1e308])],
        ];
    }

    #[DataProvider('overflowingStatistics')]
    public function test_an_overflowing_statistic_is_never_returned_as_inf(callable $statistic): void
    {
        $result = $statistic();

        $this->assertInstanceOf(ToolOutput::class, $result, 'The overflow was returned as the plain answer ' . var_export($result, true));
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('overflows', (string) $result);
    }
}
