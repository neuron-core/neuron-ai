<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\LcmTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LcmToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected LcmTool $tool;

    protected function setUp(): void
    {
        $this->tool = new LcmTool();
    }

    #[DataProvider('multiples')]
    public function test_least_common_multiple(array $numbers, string $expected): void
    {
        $this->assertSame($expected, ($this->tool)($numbers));
    }

    public static function multiples(): array
    {
        return [
            'two numbers' => [[4, 6], '12'],
            'three numbers' => [[2, 3, 5], '30'],
            'equal numbers' => [[6, 6], '6'],
            'zero absorbs' => [[0, 5], '0'],
            'sign ignored' => [[-4, 6], '12'],
            'result beyond the int range' => [[4611686018427387904, 3], '13835058055282163712'],
        ];
    }

    public function test_rejects_invalid_lists(): void
    {
        $this->assertToolError('Provide at least two integers.', ($this->tool)([]));
        $this->assertToolError('The value at index 1 is not an integer.', ($this->tool)([4, '6']));
    }
}
