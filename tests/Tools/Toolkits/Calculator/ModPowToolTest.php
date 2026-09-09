<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\ModPowTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModPowToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected ModPowTool $tool;

    protected function setUp(): void
    {
        $this->tool = new ModPowTool();
    }

    #[DataProvider('powers')]
    public function test_modular_exponentiation(int $base, int $exponent, int $modulus, string $expected): void
    {
        $this->assertSame($expected, ($this->tool)($base, $exponent, $modulus));
    }

    public static function powers(): array
    {
        return [
            'last digits of a power' => [2, 10, 1000, '24'],
            'fermat little theorem' => [2, 100, 97, '16'],
            'huge exponent' => [7, 4611686018427387904, 13, '9'],
            'zero exponent' => [3, 0, 7, '1'],
            'modulus one' => [5, 3, 1, '0'],
            'negative base gives the non-negative residue' => [-2, 3, 5, '2'],
        ];
    }

    public function test_rejects_negative_exponent_and_non_positive_modulus(): void
    {
        $this->assertToolError('The exponent must be non-negative.', ($this->tool)(2, -1, 5));
        $this->assertToolError('The modulus must be a positive integer.', ($this->tool)(2, 3, 0));
    }
}
