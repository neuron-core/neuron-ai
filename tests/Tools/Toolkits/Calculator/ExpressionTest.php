<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tools\Toolkits\Calculator\Expression;
use NeuronAI\Tools\Toolkits\Calculator\ExpressionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const M_PI_2;

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
            'tabs and newlines ignored' => ["\t2\n*\r\n3\n", 6],
            'remainder keeps the sign of the dividend' => ['-7 % 3', -1],
            'remainder with a negative divisor' => ['7 % -3', 1],
            'float remainder' => ['-7.5 % 2', -1.5],
            'zero to the zero' => ['0 ^ 0', 1],
            'trailing dot decimal' => ['1. + 1', 2],
            'rounding to tens' => ['round(1234.5, -2)', 1200],
            'minimum of negatives' => ['min(-1, -2.5)', -2.5],
            'nested function calls' => ['max(abs(-3), sqrt(16), floor(pi))', 4],
            'atan2 takes y before x' => ['atan2(1, 0)', M_PI_2],
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
            'even degree root of a negative number' => ['root(-16, 4)', 'root() is undefined at position 1'],
            'fractional power of a negative number' => ['(-8) ^ (1/3)', "'^' is undefined at position 6"],
            'logarithm of zero' => ['ln(0)', 'ln() overflows at position 1'],
            'logarithm with an invalid base' => ['log(8, 0)', 'log() is undefined at position 1'],
            'arc cosine out of its domain' => ['acos(2)', 'acos() is undefined at position 1'],
            'overflowing power' => ['10 ^ 400', "'^' overflows at position 4"],
            'overflowing literal' => ['1e999', "'1e999' overflows at position 1"],
            'division by a zero-valued group' => ['10 / (5 - 5)', 'Division by zero at position 4'],
            'modulo by a float zero' => ['5 % 0.0', 'Division by zero at position 3'],
            'float zero to a negative power' => ['0.0 ^ -1', 'Division by zero at position 5'],
            'logarithm with base one' => ['log(8, 1)', 'log() is undefined at position 1'],
            'exponential overflow' => ['exp(1000)', 'exp() overflows at position 1'],
            'hyperbolic arc tangent at its pole' => ['atanh(1)', 'atanh() overflows at position 1'],
            'overflowing product' => ['1e200 * 1e200', "'*' overflows at position 7"],
            'function without arguments' => ['min()', "Unexpected ')' at position 5"],
            'unterminated argument list' => ['sqrt(4,', 'Unexpected end of expression at position 8'],
            'two decimal points' => ['1..2', "Unexpected '.2' at position 3"],
            'too few arguments' => ['root(8)', 'Wrong number of arguments for root() at position 1'],
            'too many arguments for an optional one' => ['log(1, 2, 3)', 'Wrong number of arguments for log() at position 1'],
            'too many rounding arguments' => ['round(1, 2, 3)', 'Wrong number of arguments for round() at position 1'],
        ];
    }

    public function test_integer_limits_do_not_escape_as_arithmetic_errors(): void
    {
        $this->assertSame(0, Expression::evaluate('(-9223372036854775807 - 1) % -1'));
        $this->assertSame(1.8446744073709552E+19, Expression::evaluate('9223372036854775807 * 2'));
        $this->assertSame(1.0E+20, Expression::evaluate('99999999999999999999'));
    }

    public function test_underflow_rounds_to_zero(): void
    {
        $this->assertSame(0.0, Expression::evaluate('1e-400'));
        $this->assertSame(0.0, Expression::evaluate('2 ^ -1075'));
    }

    /**
     * The grammar is closed: PHP code, variables, constants and functions outside the
     * table are rejected by the tokenizer or parser, so nothing reaches eval().
     */
    #[DataProvider('codeInjections')]
    public function test_php_code_is_never_evaluated(string $expression, string $message): void
    {
        $this->expectException(ExpressionException::class);
        $this->expectExceptionMessage($message);

        Expression::evaluate($expression);
    }

    public static function codeInjections(): array
    {
        return [
            'function call with a string' => ['system("id")', "Unexpected character '\"' at position 8"],
            'shell backticks' => ['`id`', "Unexpected character '`' at position 1"],
            'variable' => ['$x', "Unexpected character '$' at position 1"],
            'statement separator' => ['1; phpinfo()', "Unexpected character ';' at position 2"],
            'php function outside the table' => ['exec(1)', "Unknown function 'exec' at position 1"],
            'eval' => ['eval(1)', "Unknown function 'eval' at position 1"],
            'php constant' => ['PHP_INT_MAX', "Unknown identifier 'PHP_INT_MAX' at position 1"],
            'math constant' => ['M_PI', "Unknown identifier 'M_PI' at position 1"],
            'constants are case sensitive' => ['PI', "Unknown identifier 'PI' at position 1"],
            'functions are case sensitive' => ['SQRT(4)', "Unknown function 'SQRT' at position 1"],
            'string concatenation' => ['1 . 2', "Unexpected character '.' at position 3"],
            'array access' => ['pi[0]', "Unexpected character '[' at position 3"],
            'php open tag' => ['<?php 1', "Unexpected character '<' at position 1"],
        ];
    }

    public function test_malformed_utf8_is_an_expression_error(): void
    {
        $this->expectException(ExpressionException::class);

        Expression::evaluate("1 + \xB1");
    }
}
