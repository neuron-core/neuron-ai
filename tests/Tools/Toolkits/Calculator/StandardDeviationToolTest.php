<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\StandardDeviationTool;
use PHPUnit\Framework\TestCase;

class StandardDeviationToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected StandardDeviationTool $tool;

    protected function setUp(): void
    {
        $this->tool = new StandardDeviationTool();
    }

    public function test_sample_standard_deviation_by_default(): void
    {
        $this->assertSame('2.1380899352994', ($this->tool)([2, 4, 4, 4, 5, 5, 7, 9]));
    }

    public function test_population_standard_deviation(): void
    {
        $this->assertSame('2', ($this->tool)([2, 4, 4, 4, 5, 5, 7, 9], true));
    }

    public function test_small_values_are_not_rounded_away(): void
    {
        $this->assertSame('0.001', ($this->tool)([0.001, 0.002, 0.003]));
    }

    public function test_a_sample_needs_two_values(): void
    {
        $this->assertToolError(
            'A sample needs at least two values; set population to true if this single value is the whole population.',
            ($this->tool)([5]),
        );
    }

    public function test_rejects_invalid_datasets(): void
    {
        $this->assertToolError('The value at index 1 is not a number.', ($this->tool)([1, 'x']));
    }
}
