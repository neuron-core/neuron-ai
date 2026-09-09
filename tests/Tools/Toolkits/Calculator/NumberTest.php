<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tools\Toolkits\Calculator\Number;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NumberTest extends TestCase
{
    #[DataProvider('values')]
    public function test_format(int|float $value, string $expected): void
    {
        $this->assertSame($expected, Number::format($value));
    }

    public static function values(): array
    {
        return [
            'integer' => [3, '3'],
            'negative integer' => [-42, '-42'],
            'integral float' => [3.0, '3'],
            'negative zero' => [-0.0, '0'],
            'binary rounding noise hidden' => [0.1 + 0.2, '0.3'],
            'repeating decimal cut at 15 digits' => [10 / 3, '3.33333333333333'],
            'small decimal' => [0.0015, '0.0015'],
            'large integral float printed exactly' => [1125899906842624.0, '1125899906842624'],
            'integral float beyond 2^53 in scientific notation' => [2.0 ** 53, '9.00719925474099e+15'],
            'large magnitude' => [1.0e20, '1.0e+20'],
            'tiny magnitude' => [1.0e-7, '1.0e-7'],
            'avogadro' => [6.02214076e23, '6.02214076e+23'],
        ];
    }
}
