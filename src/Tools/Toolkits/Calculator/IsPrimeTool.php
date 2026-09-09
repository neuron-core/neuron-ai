<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class IsPrimeTool extends IntegerTool
{
    protected string $name = 'is_prime';

    protected ?string $description = 'Test whether an integer is prime with a deterministic primality test. Returns true or false.';

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'number',
                type: PropertyType::INTEGER,
                description: 'The integer to test',
                required: true,
            ),
        ];
    }

    public function __invoke(int $number): string
    {
        return $this->isPrime($number) ? 'true' : 'false';
    }
}
