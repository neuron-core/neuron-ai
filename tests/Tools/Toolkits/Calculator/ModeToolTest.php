<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\ModeTool;
use PHPUnit\Framework\TestCase;

class ModeToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected ModeTool $tool;

    protected function setUp(): void
    {
        $this->tool = new ModeTool();
    }

    public function test_single_mode(): void
    {
        $this->assertSame('2', ($this->tool)([1, 2, 2, 3]));
        $this->assertSame('1.5', ($this->tool)([1.5, 1.5, 2]));
        $this->assertSame('-1', ($this->tool)([-1, -1, 0]));
    }

    public function test_integral_floats_count_as_their_integer(): void
    {
        $this->assertSame('3', ($this->tool)([3.0, 3, 2]));
    }

    public function test_ties_are_listed_in_numeric_order(): void
    {
        $this->assertSame('1, 2', ($this->tool)([1, 1, 2, 2, 3]));
        $this->assertSame('2, 10', ($this->tool)([10, 2, 10, 2]));
        $this->assertSame('1, 2, 3', ($this->tool)([1, 2, 3]));
    }

    public function test_rejects_invalid_datasets(): void
    {
        $this->assertToolError('The dataset cannot be empty.', ($this->tool)([]));
    }

    public function test_a_constant_dataset_has_a_single_mode(): void
    {
        $this->assertSame('7', ($this->tool)([7, 7, 7]));
    }

    public function test_the_framework_casts_numeric_strings_before_counting(): void
    {
        $this->tool->setInputs(['numbers' => ['2', 2, '2.0', 3]])->execute();

        $this->assertSame('2', $this->tool->getResult());
    }
}
