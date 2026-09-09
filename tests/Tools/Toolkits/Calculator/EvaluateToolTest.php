<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\Calculator\EvaluateTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_map;
use function count;
use function mt_rand;
use function mt_srand;

class EvaluateToolTest extends TestCase
{
    use ToolErrorAssertions;

    protected EvaluateTool $tool;

    protected function setUp(): void
    {
        $this->tool = new EvaluateTool();
    }

    public function test_exposes_a_single_required_expression(): void
    {
        $this->assertSame('evaluate', $this->tool->getName());
        $this->assertSame(['expression'], array_map(fn (ToolPropertyInterface $property): string => $property->getName(), $this->tool->getProperties()));
        $this->assertSame(['expression'], $this->tool->getRequiredProperties());
    }

    #[DataProvider('expressions')]
    public function test_evaluates_a_whole_formula_in_one_call(string $expression, string $expected): void
    {
        $this->assertSame($expected, ($this->tool)($expression));
    }

    public static function expressions(): array
    {
        return [
            'quadratic formula, first root' => ['(-(-7) + sqrt((-7)^2 - 4*3*2)) / (2*3)', '2'],
            'quadratic formula, second root' => ['(-(-7) - sqrt((-7)^2 - 4*3*2)) / (2*3)', '0.333333333333333'],
            'binary rounding noise hidden' => ['0.1 + 0.2', '0.3'],
            'repeating decimal' => ['10 / 3', '3.33333333333333'],
            'integer result stays exact' => ['2^62', '4611686018427387904'],
            'beyond 15 significant digits' => ['123456789012 * 987654321098', '1.21932631136586e+23'],
            'constants' => ['2 * pi', '6.28318530717959'],
            'euler number' => ['e ^ 2', '7.38905609893065'],
            'exponential and natural logarithm' => ['ln(exp(3))', '3'],
            'logarithms' => ['log(100, 10) + log2(8) + log10(1000) + log(e)', '9'],
            'trigonometry in radians' => ['sin(pi / 2) + cos(pi) + tan(pi / 4)', '1'],
            'degree conversions' => ['sin(radians(30)) + degrees(pi)', '180.5'],
            'inverse trigonometry' => ['atan2(1, 1) * 4', '3.14159265358979'],
            'hyperbolic round trips' => ['asinh(sinh(1)) + acosh(cosh(1)) + atanh(tanh(0.5))', '2.5'],
            'roots keep the sign for odd degrees' => ['sqrt(16) + cbrt(-8) + root(-32, 5) + root(16, 4)', '2'],
            'imperfect cube root prints cleanly' => ['root(64, 3)', '4'],
            'pow function' => ['pow(2, 10)', '1024'],
            'rounding functions' => ['floor(-2.5) + ceil(2.1) + round(2.567, 2) + round(2.5) + trunc(-2.7)', '3.57'],
            'min and max' => ['min(3, 1, 2) + max(3, 1, 2) + max(5)', '9'],
            'absolute value and remainders' => ['abs(-3.5) + 7 % 3 + 7.5 % 2', '6'],
            'scientific notation' => ['6.022e23 * 2', '1.2044e+24'],
        ];
    }

    public function test_reports_failures_as_tool_errors(): void
    {
        $this->assertToolError('Division by zero at position 3', ($this->tool)('1 / 0'));
        $this->assertToolError("Unknown identifier 'x' at position 1", ($this->tool)('x + 1'));
    }

    public function test_no_input_escapes_as_an_exception(): void
    {
        mt_srand(2026);
        $pieces = ['0', '1', '2.5', '1e3', '1e999', 'pi', 'e', 'x', 'sqrt', 'root', 'log', 'min', '+', '-', '*', '/', '%', '^', '**', '(', ')', ',', ' ', '.', '#', '÷'];
        $evaluated = 0;

        while ($evaluated < 3000) {
            $expression = '';
            $length = mt_rand(1, 12);

            for ($piece = 0; $piece < $length; $piece++) {
                $expression .= $pieces[mt_rand(0, count($pieces) - 1)];
            }

            try {
                ($this->tool)($expression);
            } catch (Throwable $throwable) {
                $this->fail("[{$expression}] escaped with " . $throwable::class . ': ' . $throwable->getMessage());
            }

            $evaluated++;
        }

        $this->assertSame(3000, $evaluated);
    }
}
