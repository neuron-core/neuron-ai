<?php

declare(strict_types=1);

namespace NeuronAI\Tests;

use NeuronAI\ScalarCaster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use const INF;
use const NAN;
use const PHP_INT_MAX;

class ScalarCasterTest extends TestCase
{
    #[DataProvider('conversions')]
    public function test_a_value_converts_when_nothing_is_lost(string $type, mixed $value, mixed $expected): void
    {
        $this->assertSame($expected, ScalarCaster::cast($value, $type));
    }

    public static function conversions(): array
    {
        return [
            'integer from int' => ['integer', 5, 5],
            'integer from integer string' => ['integer', '5', 5],
            'integer from integral float' => ['integer', 5.0, 5],
            'integer from signed string' => ['integer', '+5', 5],
            'integer from padded string' => ['integer', " -7\n", -7],
            'integer at the upper bound' => ['integer', '9223372036854775807', PHP_INT_MAX],
            'integer zero' => ['integer', '0', 0],
            'number from float' => ['number', 2.5, 2.5],
            'number from decimal string' => ['number', '2.5', 2.5],
            'number from integer string stays an int' => ['number', '5', 5],
            'number from scientific string' => ['number', '1e3', 1000.0],
            'number from negative decimal string' => ['number', '-0.5', -0.5],
            'number from int' => ['number', 7, 7],
            'string from string' => ['string', 'abc', 'abc'],
            'string from int' => ['string', 42, '42'],
            'string from float' => ['string', 1.5, '1.5'],
            'empty string stays empty' => ['string', '', ''],
            'multibyte string untouched' => ['string', 'caffè ☕', 'caffè ☕'],
            'boolean from bool' => ['boolean', false, false],
            'boolean from string' => ['boolean', 'true', true],
            'boolean false from string' => ['boolean', 'false', false],
            'boolean case insensitive' => ['boolean', 'TRUE', true],
            'boolean from one' => ['boolean', 1, true],
            'boolean from zero string' => ['boolean', '0', false],
        ];
    }

    #[DataProvider('losses')]
    public function test_a_value_that_would_change_is_refused(string $type, mixed $value): void
    {
        $this->assertNull(ScalarCaster::cast($value, $type));
    }

    public static function losses(): array
    {
        return [
            'integer from word' => ['integer', 'abc'],
            'integer from fractional float' => ['integer', 5.5],
            'integer from bool' => ['integer', true],
            'integer from array' => ['integer', [5]],
            'integer from decimal string' => ['integer', '5.0'],
            'integer from hexadecimal string' => ['integer', '0x1A'],
            'integer from scientific string' => ['integer', '1e3'],
            'integer beyond the int range' => ['integer', '9223372036854775808'],
            'integer from float beyond the int range' => ['integer', 1e20],
            'integer from empty string' => ['integer', ''],
            'integer from object' => ['integer', new stdClass()],
            'integer from stringable object' => ['integer', new class () {
                public function __toString(): string
                {
                    return '5';
                }
            }],
            'number from word' => ['number', 'abc'],
            'number from bool' => ['number', true],
            'number from localized decimal' => ['number', '1,5'],
            'number from infinity' => ['number', INF],
            'number from not-a-number' => ['number', NAN],
            'number overflowing a double' => ['number', '1e400'],
            'string from array' => ['string', ['a']],
            'string from bool' => ['string', false],
            'string from object' => ['string', new stdClass()],
            'boolean from word' => ['boolean', 'maybe'],
            'boolean from array' => ['boolean', [true]],
            'boolean from two' => ['boolean', 2],
            'boolean from null word' => ['boolean', 'null'],
            'boolean from null' => ['boolean', null],
        ];
    }
}
