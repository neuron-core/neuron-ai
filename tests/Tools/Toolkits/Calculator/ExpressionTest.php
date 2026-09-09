<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tools\Toolkits\Calculator\Expression;
use NeuronAI\Tools\Toolkits\Calculator\ExpressionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExpressionTest extends TestCase
{
    public function test_integer_arithmetic_stays_exact(): void
    {
        $this->assertSame(2, Expression::evaluate('6 / 3'));
        $this->assertSame(3.5, Expression::evaluate('7 / 2'));
        $this->assertSame(1024, Expression::evaluate('2 ^ 10'));
        $this->assertSame(4611686018427387904, Expression::evaluate('2 ^ 62'));
    }

    public function test_integer_overflow_degrades_to_float(): void
    {
        $this->assertSame(2.0 ** 64, Expression::evaluate('2 ^ 64'));
        $this->assertSame(9223372036854775807 + 1, Expression::evaluate('9223372036854775807 + 1'));
    }

    #[DataProvider('precedence')]
    public function test_precedence_and_associativity(string $expression, int|float $expected): void
    {
        $this->assertEquals($expected, Expression::evaluate($expression));
    }

    public static function precedence(): array
    {
        return [
            'multiplication before addition' => ['2 + 3 * 4', 14],
            'parentheses first' => ['(2 + 3) * 4', 20],
            'left-associative subtraction' => ['10 - 4 - 3', 3],
            'left-associative division' => ['100 / 10 / 2', 5],
            'right-associative power' => ['2 ^ 3 ^ 2', 512],
            'power binds tighter than unary minus' => ['-2 ^ 2', -4],
            'parenthesised negative base' => ['(-2) ^ 2', 4],
            'negative exponent' => ['2 ^ -2', 0.25],
            'unary minus inside a product' => ['2 * -3', -6],
            'double negation' => ['--3', 3],
            'unary plus' => ['+3 - -3', 6],
            'modulo with products' => ['3 % 2 * 4', 4],
            'python style power' => ['2 ** 10', 1024],
            'scientific notation' => ['1.5e3 + 2.5E-1', 1500.25],
            'leading dot decimal' => ['.5 * 4', 2],
            'whitespace ignored' => ['  2 +  3  ', 5],
        ];
    }

    #[DataProvider('errors')]
    public function test_errors_point_at_the_position(string $expression, string $message): void
    {
        $this->expectException(ExpressionException::class);
        $this->expectExceptionMessage($message);

        Expression::evaluate($expression);
    }

    public static function errors(): array
    {
        return [
            'empty' => ['', 'Unexpected end of expression at position 1'],
            'dangling operator' => ['2 +', 'Unexpected end of expression at position 4'],
            'empty group' => ['()', "Unexpected ')' at position 2"],
            'missing operator' => ['2 3', "Unexpected '3' at position 3"],
            'implicit multiplication' => ['2pi', "Unexpected 'pi' at position 2"],
            'doubled operator' => ['2 * * 3', "Unexpected '*' at position 5"],
            'unbalanced parenthesis' => ['(2 + 3', 'Unexpected end of expression at position 7'],
            'wrong closing token' => ['sqrt(4 5)', "Expected ')' but found '5' at position 8"],
            'unknown identifier' => ['x + 1', "Unknown identifier 'x' at position 1"],
            'function without parentheses' => ['sqrt 4', "Unknown identifier 'sqrt' at position 1"],
            'unknown function' => ['foo(2)', "Unknown function 'foo' at position 1"],
            'wrong arity' => ['sqrt(1, 2)', 'Wrong number of arguments for sqrt() at position 1'],
            'foreign character' => ['2 ÷ 3', "Unexpected character '÷' at position 3"],
            'division by zero' => ['1 / 0', 'Division by zero at position 3'],
            'modulo by zero' => ['5 % 0', 'Division by zero at position 3'],
            'zero to a negative power' => ['0 ^ -1', 'Division by zero at position 3'],
            'zero root degree' => ['root(8, 0)', 'Division by zero at position 1'],
            'even root of a negative number' => ['sqrt(-4)', 'sqrt() is undefined at position 1'],
            'fractional power of a negative number' => ['(-8) ^ (1/3)', "'^' is undefined at position 6"],
            'logarithm of zero' => ['ln(0)', 'ln() overflows at position 1'],
            'logarithm with an invalid base' => ['log(8, 0)', 'log() is undefined at position 1'],
            'arc cosine out of its domain' => ['acos(2)', 'acos() is undefined at position 1'],
            'overflowing power' => ['10 ^ 400', "'^' overflows at position 4"],
            'overflowing literal' => ['1e999', "'1e999' overflows at position 1"],
        ];
    }
}
