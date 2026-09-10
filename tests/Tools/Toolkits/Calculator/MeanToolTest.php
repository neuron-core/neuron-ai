<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\MeanTool;
use PHPUnit\Framework\TestCase;

class MeanToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected MeanTool $tool;

    protected function setUp(): void
    {
        $this->tool = new MeanTool();
    }

    public function test_mean(): void
    {
        $this->assertSame('2.5', ($this->tool)([1, 2, 3, 4]));
        $this->assertSame('5', ($this->tool)([5]));
        $this->assertSame('0', ($this->tool)([-1, 1]));
    }

    public function test_small_values_are_not_rounded_away(): void
    {
        $this->assertSame('0.0015', ($this->tool)([0.001, 0.002]));
    }

    public function test_rejects_invalid_datasets(): void
    {
        $this->assertToolError('The dataset cannot be empty.', ($this->tool)([]));
    }
}
