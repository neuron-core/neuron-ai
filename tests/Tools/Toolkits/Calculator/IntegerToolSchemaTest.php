<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tools\Toolkits\Calculator\CombinationsTool;
use NeuronAI\Tools\Toolkits\Calculator\IsPrimeTool;
use NeuronAI\Tools\Toolkits\Calculator\LcmTool;
use NeuronAI\Tools\Toolkits\Calculator\ModPowTool;
use NeuronAI\Tools\Toolkits\Calculator\PermutationsTool;
use NeuronAI\Tools\Toolkits\Calculator\PrimeFactorsTool;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The input schema is the contract the model calls the integer tools with.
 */
class IntegerToolSchemaTest extends TestCase
{
    /**
     * @param class-string<ToolInterface> $class
     * @param array<string, mixed> $properties
     * @param string[] $required
     */
    #[DataProvider('schemas')]
    public function test_input_schema(string $class, array $properties, array $required): void
    {
        $this->assertSame(
            ['type' => 'object', 'properties' => $properties, 'required' => $required],
            (new $class())->getInputSchema()
        );
    }

    public static function schemas(): array
    {
        $integer = fn (string $description): array => ['type' => 'integer', 'description' => $description];

        return [
            'combinations' => [
                CombinationsTool::class,
                ['n' => $integer('The number of items to choose from'), 'k' => $integer('The number of items to choose')],
                ['n', 'k'],
            ],
            'permutations' => [
                PermutationsTool::class,
                ['n' => $integer('The number of items to choose from'), 'k' => $integer('The number of items to arrange')],
                ['n', 'k'],
            ],
            'modular exponentiation' => [
                ModPowTool::class,
                ['base' => $integer('The base'), 'exponent' => $integer('The non-negative exponent'), 'modulus' => $integer('The positive modulus')],
                ['base', 'exponent', 'modulus'],
            ],
            'primality' => [IsPrimeTool::class, ['number' => $integer('The integer to test')], ['number']],
            'prime factors' => [PrimeFactorsTool::class, ['number' => $integer('The integer to factor, greater than 1')], ['number']],
            'least common multiple' => [
                LcmTool::class,
                [
                    'numbers' => [
                        'type' => 'array',
                        'description' => 'The integers to compute the least common multiple of',
                        'items' => $integer('An integer'),
                        'minItems' => 2,
                    ],
                ],
                ['numbers'],
            ],
        ];
    }
}
