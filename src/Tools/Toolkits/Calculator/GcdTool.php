<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function abs;
use function array_reduce;

class GcdTool extends IntegerTool
{
    protected string $name = 'gcd';

    protected ?string $description = 'Calculate the greatest common divisor of two or more integers.';

    protected function properties(): array
    {
        return [
            new ArrayProperty(
                name: 'numbers',
                description: 'The integers to compute the greatest common divisor of',
                required: true,
                items: new ToolProperty('number', PropertyType::INTEGER, 'An integer', true),
                minItems: 2,
            ),
        ];
    }

    public function __invoke(array $numbers): string|ToolOutput
    {
        return $this->invalidIntegers($numbers)
            ?? array_reduce($numbers, fn (string $gcd, int $number): string => $this->gcd($gcd, (string) abs($number)), '0');
    }
}
