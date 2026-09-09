<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function array_keys;
use function array_map;
use function implode;
use function intdiv;

class PrimeFactorsTool extends IntegerTool
{
    protected const TRIAL_DIVISION_LIMIT = 1_000_000;

    protected string $name = 'prime_factors';

    protected ?string $description = 'Decompose an integer greater than 1 into its prime factors, written as a product like 2^3 * 3 * 5.';

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'number',
                type: PropertyType::INTEGER,
                description: 'The integer to factor, greater than 1',
                required: true,
            ),
        ];
    }

    public function __invoke(int $number): string|ToolOutput
    {
        if ($number < 2) {
            return ToolOutput::error('Prime factorization is defined for integers greater than 1.');
        }

        $exponents = [];
        $remaining = $number;

        // Trial divisors: 2, then odd numbers only
        for ($divisor = 2; $divisor * $divisor <= $remaining && $divisor <= self::TRIAL_DIVISION_LIMIT; $divisor += $divisor === 2 ? 1 : 2) {
            while ($remaining % $divisor === 0) {
                $exponents[$divisor] = ($exponents[$divisor] ?? 0) + 1;
                $remaining = intdiv($remaining, $divisor);
            }
        }

        if ($remaining > 1) {
            // Only a leftover beyond the trial limit can still be composite, and then it is out of reach
            if (!$this->isPrime($remaining)) {
                return ToolOutput::error("{$number} has the composite factor {$remaining} with no prime divisor below " . self::TRIAL_DIVISION_LIMIT . ', so it cannot be fully factored.');
            }

            $exponents[$remaining] = 1;
        }

        return implode(' * ', array_map(
            fn (int $prime, int $exponent): string => $exponent === 1 ? (string) $prime : "{$prime}^{$exponent}",
            array_keys($exponents),
            $exponents,
        ));
    }
}
