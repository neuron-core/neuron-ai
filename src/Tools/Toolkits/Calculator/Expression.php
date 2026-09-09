<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use function abs;
use function acos;
use function acosh;
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

    protected const FUNCTIONS = [
        'sqrt', 'cbrt', 'root', 'pow', 'abs', 'exp', 'ln', 'log', 'log10', 'log2',
        'sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'atan2',
        'sinh', 'cosh', 'tanh', 'asinh', 'acosh', 'atanh',
        'floor', 'ceil', 'round', 'trunc', 'min', 'max', 'radians', 'degrees',
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
            foreach (['number', 'name', 'operator'] as $type) {
                if ($match[$type][0] !== null) {
                    $text = $match[$type][0] === '**' ? '^' : $match[$type][0];
                    $this->tokens[] = ['type' => $type, 'text' => $text, 'offset' => $match[$type][1]];
                    continue 2;
                }
            }

            throw ExpressionException::at("Unexpected character '{$match['invalid'][0]}'", $match['invalid'][1]);
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
            return $this->number($token);
        }

        if ($token['type'] === 'name') {
            return $this->accept('(') !== null ? $this->call($token) : $this->constant($token);
        }

        if ($token['text'] === '(') {
            $value = $this->parseSum();
            $this->expect(')');

            return $value;
        }

        throw ExpressionException::at("Unexpected '{$token['text']}'", $token['offset']);
    }

    /**
     * @param array{type: string, text: string, offset: int} $token
     */
    protected function number(array $token): int|float
    {
        $integer = filter_var($token['text'], FILTER_VALIDATE_INT);
        $value = $integer === false ? (float) $token['text'] : $integer;

        return $this->finite($value, "'{$token['text']}'", $token['offset']);
    }

    /**
     * @param array{type: string, text: string, offset: int} $token
     */
    protected function constant(array $token): float
    {
        return self::CONSTANTS[$token['text']] ?? throw ExpressionException::at("Unknown identifier '{$token['text']}'", $token['offset']);
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
        if ($name === 'min' || $name === 'max') {
            return $name === 'min' ? min($arguments) : max($arguments);
        }

        $x = $arguments[0];
        $y = $arguments[1] ?? 0;

        return match ([$name, count($arguments)]) {
            ['sqrt', 1] => sqrt($x),
            ['cbrt', 1] => $this->root($x, 3, $offset),
            ['root', 2] => $this->root($x, $y, $offset),
            ['pow', 2] => $this->power($x, $y, $offset),
            ['abs', 1] => abs($x),
            ['exp', 1] => exp($x),
            ['ln', 1], ['log', 1] => log($x),
            ['log', 2] => $y > 0 ? log($x, $y) : NAN,
            ['log10', 1] => log10($x),
            ['log2', 1] => log($x, 2),
            ['sin', 1] => sin($x),
            ['cos', 1] => cos($x),
            ['tan', 1] => tan($x),
            ['asin', 1] => asin($x),
            ['acos', 1] => acos($x),
            ['atan', 1] => atan($x),
            ['atan2', 2] => atan2($x, $y),
            ['sinh', 1] => sinh($x),
            ['cosh', 1] => cosh($x),
            ['tanh', 1] => tanh($x),
            ['asinh', 1] => asinh($x),
            ['acosh', 1] => acosh($x),
            ['atanh', 1] => atanh($x),
            ['floor', 1] => floor($x),
            ['ceil', 1] => ceil($x),
            ['round', 1] => round($x),
            ['round', 2] => round($x, (int) $y),
            ['trunc', 1] => $x < 0 ? ceil($x) : floor($x),
            ['radians', 1] => deg2rad($x),
            ['degrees', 1] => rad2deg($x),
            default => throw ExpressionException::at(
                in_array($name, self::FUNCTIONS, true) ? "Wrong number of arguments for {$name}()" : "Unknown function '{$name}'",
                $offset,
            ),
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
        if ($exponent < 0) {
            $this->nonZero($base, $offset);
        }

        return $base ** $exponent;
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
