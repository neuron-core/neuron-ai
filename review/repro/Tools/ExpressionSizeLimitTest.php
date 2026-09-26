<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tools\Toolkits\Calculator\EvaluateTool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function memory_get_peak_usage;
use function str_repeat;

class ExpressionSizeLimitTest extends TestCase
{
    public function test_an_oversized_expression_is_returned_to_the_model_as_an_error(): void
    {
        $peakBefore = memory_get_peak_usage();

        // 100 KB of model input: tokenizing it needs more than a 128M memory_limit today
        $result = (new EvaluateTool())(str_repeat('1+', 50_000) . '1');

        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $peakBefore);
    }

    public function test_a_long_but_reasonable_expression_is_still_evaluated(): void
    {
        $this->assertSame('1000', (new EvaluateTool())(str_repeat('1+', 999) . '1'));
    }
}
