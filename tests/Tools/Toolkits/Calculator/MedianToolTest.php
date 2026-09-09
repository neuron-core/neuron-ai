<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\MedianTool;
use PHPUnit\Framework\TestCase;

class MedianToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected MedianTool $tool;

    protected function setUp(): void
    {
        $this->tool = new MedianTool();
    }

    public function test_odd_count_takes_the_middle_value(): void
    {
        $this->assertSame('2', ($this->tool)([3, 1, 2]));
        $this->assertSame('5', ($this->tool)([5]));
    }

    public function test_even_count_averages_the_two_middle_values(): void
    {
        $this->assertSame('2.5', ($this->tool)([4, 1, 3, 2]));
        $this->assertSame('1', ($this->tool)([1.5, 0.5]));
    }

    public function test_rejects_invalid_datasets(): void
    {
        $this->assertToolError('The dataset cannot be empty.', ($this->tool)([]));
        $this->assertToolError('The value at index 0 is not a number.', ($this->tool)(['1', 2]));
    }
}
