<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\FactorialTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function rtrim;
use function strlen;

class FactorialToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected FactorialTool $tool;

    protected function setUp(): void
    {
        $this->tool = new FactorialTool();
    }

    #[DataProvider('factorials')]
    public function test_exact_factorial(int $n, string $expected): void
    {
        $this->assertSame($expected, ($this->tool)($n));
    }

    public static function factorials(): array
    {
        return [
            [0, '1'],
            [1, '1'],
            [5, '120'],
            [20, '2432902008176640000'],
            [25, '15511210043330985984000000'],
            [30, '265252859812191058636308480000000'],
        ];
    }

    public function test_largest_allowed_input(): void
    {
        $factorial = ($this->tool)(1000);

        $this->assertSame(2568, strlen($factorial));
        $this->assertSame(249, strlen($factorial) - strlen(rtrim($factorial, '0')));
    }

    public function test_rejects_negative_and_oversized_inputs(): void
    {
        $this->assertToolError('The factorial is available for integers between 0 and 1000.', ($this->tool)(-1));
        $this->assertToolError('The factorial is available for integers between 0 and 1000.', ($this->tool)(1001));
    }
}
