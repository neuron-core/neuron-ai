<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calculator;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function bcadd;
use function bccomp;
use function bcpowmod;

class ModPowTool extends IntegerTool
{
    protected string $name = 'mod_pow';

    protected ?string $description = <<<DESC
        Calculate base^exponent mod modulus exactly, even for huge exponents, through modular
        exponentiation. Use it for number theory, cryptography and "last digits of a power" questions.
        DESC;

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'base',
                type: PropertyType::INTEGER,
                description: 'The base',
                required: true,
            ),
            ToolProperty::make(
                name: 'exponent',
                type: PropertyType::INTEGER,
                description: 'The non-negative exponent',
                required: true,
            ),
            ToolProperty::make(
                name: 'modulus',
                type: PropertyType::INTEGER,
                description: 'The positive modulus',
                required: true,
            ),
        ];
    }

    public function __invoke(int $base, int $exponent, int $modulus): string|ToolOutput
    {
        if ($exponent < 0) {
            return ToolOutput::error('The exponent must be non-negative.');
        }

        if ($modulus < 1) {
            return ToolOutput::error('The modulus must be a positive integer.');
        }

        $remainder = bcpowmod((string) $base, (string) $exponent, (string) $modulus);

        // bcmath keeps the sign of a negative base; the canonical residue is non-negative
        return bccomp($remainder, '0') < 0 ? bcadd($remainder, (string) $modulus) : $remainder;
    }
}
