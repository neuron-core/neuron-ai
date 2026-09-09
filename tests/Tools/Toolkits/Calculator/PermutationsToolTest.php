<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\PermutationsTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PermutationsToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected PermutationsTool $tool;

    protected function setUp(): void
    {
        $this->tool = new PermutationsTool();
    }

    #[DataProvider('permutations')]
    public function test_exact_permutations(int $n, int $k, string $expected): void
    {
        $this->assertSame($expected, ($this->tool)($n, $k));
    }

    public static function permutations(): array
    {
        return [
            [5, 2, '20'],
            [0, 0, '1'],
            [10, 0, '1'],
            [10, 10, '3628800'],
            [52, 5, '311875200'],
            [3000, 2, '8997000'],
        ];
    }

    public function test_rejects_invalid_ranges(): void
    {
        $this->assertToolError('Permutations require 0 <= k <= n.', ($this->tool)(2, 3));
        $this->assertToolError('Permutations require 0 <= k <= n.', ($this->tool)(-1, 0));
    }

    public function test_rejects_too_many_terms(): void
    {
        $this->assertToolError('k must not exceed 1000.', ($this->tool)(2000, 1001));
    }
}
