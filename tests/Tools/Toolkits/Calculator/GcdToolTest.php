<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\GcdTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GcdToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected GcdTool $tool;

    protected function setUp(): void
    {
        $this->tool = new GcdTool();
    }

    #[DataProvider('divisors')]
    public function test_greatest_common_divisor(array $numbers, string $expected): void
    {
        $this->assertSame($expected, ($this->tool)($numbers));
    }

    public static function divisors(): array
    {
        return [
            'two numbers' => [[12, 18], '6'],
            'three numbers' => [[12, 18, 24], '6'],
            'coprime' => [[7, 13], '1'],
            'zero is neutral' => [[0, 5], '5'],
            'all zeros' => [[0, 0], '0'],
            'sign ignored' => [[-12, 18], '6'],
            'large powers of two' => [[4611686018427387904, 3298534883328], '1099511627776'],
        ];
    }

    public function test_rejects_invalid_lists(): void
    {
        $this->assertToolError('Provide at least two integers.', ($this->tool)([7]));
        $this->assertToolError('The value at index 1 is not an integer.', ($this->tool)([7, 'a']));
        $this->assertToolError('The value at index 0 is not an integer.', ($this->tool)([2.5, 5]));
    }
}
