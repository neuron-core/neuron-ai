<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function abs;
use function array_reduce;
use function bcdiv;
use function bcmul;

class LcmTool extends IntegerTool
{
    protected string $name = 'lcm';

    protected ?string $description = 'Calculate the least common multiple of two or more integers exactly, as an integer of any size.';

    protected function properties(): array
    {
        return [
            new ArrayProperty(
                name: 'numbers',
                description: 'The integers to compute the least common multiple of',
                required: true,
                items: new ToolProperty('number', PropertyType::INTEGER, 'An integer', true),
                minItems: 2,
            ),
        ];
    }

    public function __invoke(array $numbers): string|ToolOutput
    {
        return $this->invalidIntegers($numbers)
            ?? array_reduce($numbers, fn (string $lcm, int $number): string => $this->lcm($lcm, (string) abs($number)), '1');
    }

    protected function lcm(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') {
            return '0';
        }

        return bcmul(bcdiv($a, $this->gcd($a, $b)), $b);
    }
}
