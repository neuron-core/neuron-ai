<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\MeanTool;
use PHPUnit\Framework\TestCase;

use const PHP_INT_MAX;

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
        $this->assertSame('0.15', ($this->tool)([0.1, 0.2]));
        $this->assertSame('9.22337203685478e+18', ($this->tool)([PHP_INT_MAX, PHP_INT_MAX]));
    }

    public function test_small_values_are_not_rounded_away(): void
    {
        $this->assertSame('0.0015', ($this->tool)([0.001, 0.002]));
    }

    public function test_rejects_invalid_datasets(): void
    {
        $this->assertToolError('The dataset cannot be empty.', ($this->tool)([]));
    }

    public function test_exposes_a_non_empty_list_of_numbers(): void
    {
        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'numbers' => [
                    'type' => 'array',
                    'description' => 'The dataset as a list of numbers',
                    'items' => ['type' => 'number', 'description' => 'A numerical value'],
                    'minItems' => 1,
                ],
            ],
            'required' => ['numbers'],
        ], $this->tool->getInputSchema());
    }

    public function test_the_framework_casts_numeric_strings(): void
    {
        $this->tool->setInputs(['numbers' => ['1', '2.5']])->execute();

        $this->assertSame('1.75', $this->tool->getResult());
    }

    public function test_the_framework_rejects_a_non_numeric_value(): void
    {
        $this->tool->setInputs(['numbers' => [1, 'ten']])->execute();

        $this->assertToolError('Parameter "numbers" element 1 must be of type number, string given.', $this->tool->getResult());
    }
}
