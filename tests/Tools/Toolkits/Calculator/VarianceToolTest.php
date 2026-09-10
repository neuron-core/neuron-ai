<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\VarianceTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;

class VarianceToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected VarianceTool $tool;

    protected function setUp(): void
    {
        $this->tool = new VarianceTool();
    }

    public function test_the_model_chooses_between_sample_and_population(): void
    {
        $names = array_map(fn (ToolPropertyInterface $property): string => $property->getName(), $this->tool->getProperties());

        $this->assertSame(['numbers', 'population'], $names);
        $this->assertSame(['numbers'], $this->tool->getRequiredProperties());
    }

    public function test_sample_variance_by_default(): void
    {
        $this->assertSame('4.57142857142857', ($this->tool)([2, 4, 4, 4, 5, 5, 7, 9]));
        $this->assertSame('1.0e-6', ($this->tool)([0.001, 0.002, 0.003]));
    }

    public function test_population_variance(): void
    {
        $this->assertSame('4', ($this->tool)([2, 4, 4, 4, 5, 5, 7, 9], true));
        $this->assertSame('6.66666666666667e-7', ($this->tool)([0.001, 0.002, 0.003], true));
        $this->assertSame('0', ($this->tool)([5], true));
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
        $this->assertToolError('The dataset cannot be empty.', ($this->tool)([]));
    }
}
