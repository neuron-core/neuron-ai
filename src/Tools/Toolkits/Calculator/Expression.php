<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use function abs;
use function acos;
use function acosh;
use function array_key_exists;
use function asin;
use function asinh;
use function atan;
use function atan2;
use function atanh;
use function ceil;
use function cos;
use function cosh;
use function count;
use function deg2rad;
use function exp;
use function filter_var;
use function floor;
use function fmod;
use function in_array;
use function is_infinite;
use function is_int;
use function is_nan;
use function log;
use function log10;
use function max;
use function min;
use function preg_match_all;
use function rad2deg;
use function round;
use function sin;
use function sinh;
use function sqrt;
use function strlen;
use function tan;
use function tanh;

use const FILTER_VALIDATE_INT;
use const M_E;
use const M_PI;
use const NAN;
use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;
use const PREG_UNMATCHED_AS_NULL;

/**
 * Evaluates a mathematical expression with a recursive-descent parser and never
 * through eval(): the grammar is closed over numbers, the operators + - * / % ^,
 * parentheses, the constants pi and e, and a fixed function table.
 *
 *   sum     := product (('+' | '-') product)*
 *   product := unary (('*' | '/' | '%') unary)*
 *   unary   := ('-' | '+') unary | power
 *   power   := atom ('^' unary)?
 *   atom    := NUMBER | NAME | NAME '(' sum (',' sum)* ')' | '(' sum ')'
 */
class Expression
{
    protected const TOKEN_PATTERN = '/\s*(?:(?<number>(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)|(?<name>[a-zA-Z_]\w*)|(?<operator>\*\*|[-+*\/%^(),])|(?<invalid>\S))/Au';

    protected const CONSTANTS = ['pi' => M_PI, 'e' => M_E];

    /**
     * The argument counts each function accepts; null accepts any number of arguments.
     */
    protected const ARITY = [
        'sqrt' => [1], 'cbrt' => [1], 'root' => [2], 'pow' => [2], 'abs' => [1], 'exp' => [1],
        'ln' => [1], 'log' => [1, 2], 'log10' => [1], 'log2' => [1],
        'sin' => [1], 'cos' => [1], 'tan' => [1], 'asin' => [1], 'acos' => [1], 'atan' => [1], 'atan2' => [2],
        'sinh' => [1], 'cosh' => [1], 'tanh' => [1], 'asinh' => [1], 'acosh' => [1], 'atanh' => [1],
        'floor' => [1], 'ceil' => [1], 'round' => [1, 2], 'trunc' => [1], 'min' => null, 'max' => null,
        'radians' => [1], 'degrees' => [1],
    ];

    /**
     * @var array<int, array{type: string, text: string, offset: int}>
     */
    protected array $tokens = [];

    protected int $index = 0;

    protected function __construct(protected string $source)
    {
    }

    /**
     * @throws ExpressionException
     */
    public static function evaluate(string $source): int|float
    {
        $expression = new self($source);
        $expression->tokenize();

        $value = $expression->parseSum();

        if (isset($expression->tokens[$expression->index])) {
            $token = $expression->tokens[$expression->index];
            throw ExpressionException::at("Unexpected '{$token['text']}'", $token['offset']);
        }

        return $value;
    }

    /**
     * @throws ExpressionException
     */
    protected function tokenize(): void
    {
        preg_match_all(self::TOKEN_PATTERN, $this->source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        foreach ($matches as $match) {
            $type = match (true) {
                $match['number'][0] !== null => 'number',
                $match['name'][0] !== null => 'name',
                $match['operator'][0] !== null => 'operator',
                default => throw ExpressionException::at("Unexpected character '{$match['invalid'][0]}'", $match['invalid'][1]),
            };

            $text = $match[$type][0];

            $this->tokens[] = ['type' => $type, 'text' => $text === '**' ? '^' : $text, 'offset' => $match[$type][1]];
        }
    }

    protected function parseSum(): int|float
    {
        $value = $this->parseProduct();

        while (($operator = $this->accept('+', '-')) !== null) {
            $value = $this->apply($operator, $value, $this->parseProduct());
        }

        return $value;
    }

    protected function parseProduct(): int|float
    {
        $value = $this->parseUnary();

        while (($operator = $this->accept('*', '/', '%')) !== null) {
            $value = $this->apply($operator, $value, $this->parseUnary());
        }

        return $value;
    }

    protected function parseUnary(): int|float
    {
        if ($this->accept('-') !== null) {
            return -$this->parseUnary();
        }

        if ($this->accept('+') !== null) {
            return $this->parseUnary();
        }

        return $this->parsePower();
    }

    protected function parsePower(): int|float
    {
        $base = $this->parseAtom();
        $operator = $this->accept('^');

        return $operator === null ? $base : $this->apply($operator, $base, $this->parseUnary());
    }

    protected function parseAtom(): int|float
    {
        $token = $this->advance();

        if ($token['type'] === 'number') {
            $integer = filter_var($token['text'], FILTER_VALIDATE_INT);

            return $this->finite($integer === false ? (float) $token['text'] : $integer, "'{$token['text']}'", $token['offset']);
        }

        if ($token['type'] === 'name') {
            return $this->accept('(') !== null
                ? $this->call($token)
                : self::CONSTANTS[$token['text']] ?? throw ExpressionException::at("Unknown identifier '{$token['text']}'", $token['offset']);
        }

        if ($token['text'] === '(') {
            $value = $this->parseSum();
            $this->expect(')');

            return $value;
        }

        throw ExpressionException::at("Unexpected '{$token['text']}'", $token['offset']);
    }

    /**
     * @param array{type: string, text: string, offset: int} $function
     */
    protected function call(array $function): int|float
    {
        $arguments = [$this->parseSum()];

        while ($this->accept(',') !== null) {
            $arguments[] = $this->parseSum();
        }

        $this->expect(')');

        $value = $this->invoke($function['text'], $arguments, $function['offset']);

        return $this->finite($value, "{$function['text']}()", $function['offset']);
    }

    /**
     * @param array<int|float> $arguments
     */
    protected function invoke(string $name, array $arguments, int $offset): int|float
    {
        if (!array_key_exists($name, self::ARITY)) {
            throw ExpressionException::at("Unknown function '{$name}'", $offset);
        }

        if (self::ARITY[$name] !== null && !in_array(count($arguments), self::ARITY[$name], true)) {
            throw ExpressionException::at("Wrong number of arguments for {$name}()", $offset);
        }

        $x = $arguments[0];
        $y = $arguments[1] ?? 0;

        return match ($name) {
            'sqrt' => sqrt($x),
            'cbrt' => $this->root($x, 3, $offset),
            'root' => $this->root($x, $y, $offset),
            'pow' => $this->power($x, $y, $offset),
            'abs' => abs($x),
            'exp' => exp($x),
            'ln' => log($x),
            'log' => count($arguments) === 1 ? log($x) : ($y > 0 ? log($x, $y) : NAN),
            'log10' => log10($x),
            'log2' => log($x, 2),
            'sin' => sin($x),
            'cos' => cos($x),
            'tan' => tan($x),
            'asin' => asin($x),
            'acos' => acos($x),
            'atan' => atan($x),
            'atan2' => atan2($x, $y),
            'sinh' => sinh($x),
            'cosh' => cosh($x),
            'tanh' => tanh($x),
            'asinh' => asinh($x),
            'acosh' => acosh($x),
            'atanh' => atanh($x),
            'floor' => floor($x),
            'ceil' => ceil($x),
            'round' => round($x, (int) $y),
            'trunc' => $x < 0 ? ceil($x) : floor($x),
            'min' => min($arguments),
            'max' => max($arguments),
            'radians' => deg2rad($x),
            'degrees' => rad2deg($x),
        };
    }

    /**
     * @param array{type: string, text: string, offset: int} $operator
     */
    protected function apply(array $operator, int|float $left, int|float $right): int|float
    {
        $symbol = $operator['text'];
        $offset = $operator['offset'];

        $value = match ($symbol) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $left / $this->nonZero($right, $offset),
            '%' => $this->modulo($left, $this->nonZero($right, $offset)),
            '^' => $this->power($left, $right, $offset),
            default => throw ExpressionException::at("Unknown operator '{$symbol}'", $offset),
        };

        return $this->finite($value, "'{$symbol}'", $offset);
    }

    protected function modulo(int|float $dividend, int|float $divisor): int|float
    {
        return is_int($dividend) && is_int($divisor) ? $dividend % $divisor : fmod($dividend, $divisor);
    }

    protected function power(int|float $base, int|float $exponent, int $offset): int|float
    {
        return $exponent < 0 ? $this->nonZero($base, $offset) ** $exponent : $base ** $exponent;
    }

    protected function root(int|float $radicand, int|float $degree, int $offset): int|float
    {
        $exponent = 1 / $this->nonZero($degree, $offset);

        // An odd integer degree keeps the sign of a negative radicand: root(-8, 3) = -2
        $oddDegree = floor($degree) == $degree && fmod($degree, 2) != 0;

        return $radicand < 0 && $oddDegree
            ? -$this->power(-$radicand, $exponent, $offset)
            : $this->power($radicand, $exponent, $offset);
    }

    protected function nonZero(int|float $value, int $offset): int|float
    {
        if ($value == 0) {
            throw ExpressionException::at('Division by zero', $offset);
        }

        return $value;
    }

    protected function finite(int|float $value, string $subject, int $offset): int|float
    {
        if (is_nan($value)) {
            throw ExpressionException::at("{$subject} is undefined", $offset);
        }

        if (is_infinite($value)) {
            throw ExpressionException::at("{$subject} overflows", $offset);
        }

        return $value;
    }

    /**
     * Consumes the next token when it is one of the given operators.
     *
     * @return array{type: string, text: string, offset: int}|null
     */
    protected function accept(string ...$operators): ?array
    {
        $token = $this->tokens[$this->index] ?? null;

        if ($token === null || $token['type'] !== 'operator' || !in_array($token['text'], $operators, true)) {
            return null;
        }

        $this->index++;

        return $token;
    }

    protected function expect(string $operator): void
    {
        if ($this->accept($operator) === null) {
            $token = $this->advance();
            throw ExpressionException::at("Expected '{$operator}' but found '{$token['text']}'", $token['offset']);
        }
    }

    /**
     * @return array{type: string, text: string, offset: int}
     */
    protected function advance(): array
    {
        return $this->tokens[$this->index++] ?? throw ExpressionException::at('Unexpected end of expression', strlen($this->source));
    }
}
