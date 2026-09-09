<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tools\Toolkits\Calculator\IsPrimeTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IsPrimeToolTest extends TestCase
{
    protected IsPrimeTool $tool;

    protected function setUp(): void
    {
        $this->tool = new IsPrimeTool();
    }

    #[DataProvider('primes')]
    public function test_recognises_primes(int $number): void
    {
        $this->assertSame('true', ($this->tool)($number));
    }

    public static function primes(): array
    {
        return [
            'smallest' => [2],
            'odd witness' => [3],
            'largest witness' => [37],
            'first non-witness' => [41],
            'two digits' => [97],
            'first above a million' => [1000003],
            'common modulus' => [1000000007],
            'mersenne prime 2^61 - 1' => [2305843009213693951],
            'largest prime below 2^63' => [9223372036854775783],
        ];
    }

    #[DataProvider('composites')]
    public function test_rejects_composites_and_non_primes(int $number): void
    {
        $this->assertSame('false', ($this->tool)($number));
    }

    public static function composites(): array
    {
        return [
            'zero' => [0],
            'one' => [1],
            'negative' => [-7],
            'even' => [4],
            'square of a witness' => [9],
            'square of the largest witness' => [1369],
            'carmichael number' => [561],
            'base-2 pseudoprime 2^11 - 1' => [2047],
            'strong pseudoprime to bases 2, 3, 5 and 7' => [3215031751],
            'product of two primes above a million' => [1000036000099],
            'int max' => [9223372036854775807],
        ];
    }
}
