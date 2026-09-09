<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\PrimeFactorsTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PrimeFactorsToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected PrimeFactorsTool $tool;

    protected function setUp(): void
    {
        $this->tool = new PrimeFactorsTool();
    }

    #[DataProvider('factorizations')]
    public function test_prime_factorization(int $number, string $expected): void
    {
        $this->assertSame($expected, ($this->tool)($number));
    }

    public static function factorizations(): array
    {
        return [
            'prime' => [2, '2'],
            'repeated factor' => [12, '2^2 * 3'],
            'several exponents' => [360, '2^3 * 3^2 * 5'],
            'two digit prime' => [97, '97'],
            'prime above the trial limit' => [1000003, '1000003'],
            'small factor times a prime above the trial limit' => [3000009, '3 * 1000003'],
            'project euler 3' => [600851475143, '71 * 839 * 1471 * 6857'],
            'power of two' => [4611686018427387904, '2^62'],
            'int max' => [9223372036854775807, '7^2 * 73 * 127 * 337 * 92737 * 649657'],
            'largest prime below 2^63' => [9223372036854775783, '9223372036854775783'],
        ];
    }

    public function test_rejects_numbers_below_two(): void
    {
        $this->assertToolError('Prime factorization is defined for integers greater than 1.', ($this->tool)(1));
        $this->assertToolError('Prime factorization is defined for integers greater than 1.', ($this->tool)(0));
        $this->assertToolError('Prime factorization is defined for integers greater than 1.', ($this->tool)(-12));
    }

    public function test_reports_a_composite_out_of_reach(): void
    {
        $this->assertToolError(
            '1000036000099 has the composite factor 1000036000099 with no prime divisor below 1000000, so it cannot be fully factored.',
            ($this->tool)(1000036000099),
        );
    }
}
