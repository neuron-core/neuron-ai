<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

class EvaluateTool extends Tool
{
    protected string $name = 'evaluate';

    protected ?string $description = <<<DESC
        Evaluate a mathematical expression and return its numeric result, computed deterministically
        in double precision (about 15 significant digits). Write the whole formula in a single call
        instead of computing intermediate steps yourself.
        Syntax: numbers including decimals and scientific notation (6.022e23); the operators + - * /,
        % for the remainder and ^ for powers (right-associative, ** is accepted too); parentheses;
        the constants pi and e. Multiplication must be explicit: write 2*pi, not 2pi.
        Functions: sqrt(x), cbrt(x), root(x, n), pow(x, y), abs(x), exp(x), ln(x), log(x) natural
        logarithm, log(x, base), log10(x), log2(x), sin(x), cos(x), tan(x), asin(x), acos(x), atan(x),
        atan2(y, x), sinh(x), cosh(x), tanh(x), asinh(x), acosh(x), atanh(x), floor(x), ceil(x),
        round(x), round(x, digits), trunc(x), min(a, b, ...), max(a, b, ...), radians(degrees),
        degrees(radians). Trigonometric functions work in radians.
        Example: (-7 + sqrt(7^2 - 4*3*2)) / (2*3)
        DESC;

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'expression',
                type: PropertyType::STRING,
                description: 'The mathematical expression to evaluate',
                required: true,
            ),
        ];
    }

    public function __invoke(string $expression): string|ToolOutput
    {
        try {
            return Number::format(Expression::evaluate($expression));
        } catch (ExpressionException $exception) {
            return ToolOutput::error($exception->getMessage());
        }
    }
}
