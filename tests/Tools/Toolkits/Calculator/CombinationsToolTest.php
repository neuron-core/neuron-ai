<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\CombinationsTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function strlen;
use function str_ends_with;
use function str_starts_with;

class CombinationsToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected CombinationsTool $tool;

    protected function setUp(): void
    {
        $this->tool = new CombinationsTool();
    }

    #[DataProvider('combinations')]
    public function test_exact_binomial_coefficient(int $n, int $k, string $expected): void
    {
        $this->assertSame($expected, ($this->tool)($n, $k));
    }

    public static function combinations(): array
    {
        return [
            [5, 2, '10'],
            [10, 0, '1'],
            [10, 10, '1'],
            [52, 5, '2598960'],
            [100, 50, '100891344545564193334812497256'],
            [3000, 2, '4498500'],
        ];
    }

    public function test_largest_allowed_symmetric_case(): void
    {
        $combinations = ($this->tool)(1000, 500);

        $this->assertSame(300, strlen($combinations));
        $this->assertTrue(str_starts_with($combinations, '270288240945436569515614'));
        $this->assertTrue(str_ends_with($combinations, '799821216320'));
    }

    public function test_rejects_invalid_ranges(): void
    {
        $this->assertToolError('Combinations require 0 <= k <= n.', ($this->tool)(3, 5));
        $this->assertToolError('Combinations require 0 <= k <= n.', ($this->tool)(-1, 0));
        $this->assertToolError('Combinations require 0 <= k <= n.', ($this->tool)(5, -1));
    }

    public function test_rejects_too_many_terms(): void
    {
        $this->assertToolError('The smaller of k and n - k must not exceed 1000.', ($this->tool)(3000, 1500));
    }
}
