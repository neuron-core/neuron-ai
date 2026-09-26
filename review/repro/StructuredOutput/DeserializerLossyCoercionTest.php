<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Deserializer;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeserializerLossyCoercionTest extends TestCase
{
    public function test_quoted_false_is_deserialized_as_false(): void
    {
        $class = new class () {
            public bool $approved;
        };

        $obj = Deserializer::make()->fromJson('{"approved": "false"}', $class::class);

        $this->assertFalse($obj->approved);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lossyScalarPayloads(): array
    {
        return [
            'non-numeric string to int' => ['{"age": "unknown"}'],
            'fractional float to int' => ['{"age": 2.7}'],
            'array to int' => ['{"age": [30]}'],
            'non-numeric string to float' => ['{"ratio": "n/a"}'],
            'array to string' => ['{"name": ["John"]}'],
            'object to string' => ['{"name": {"first": "John"}}'],
            'arbitrary string to bool' => ['{"approved": "maybe"}'],
        ];
    }

    #[DataProvider('lossyScalarPayloads')]
    public function test_values_that_cannot_be_losslessly_cast_are_rejected(string $json): void
    {
        $class = new class () {
            public int $age;
            public float $ratio;
            public string $name;
            public bool $approved;
        };

        $this->expectException(DeserializerException::class);

        Deserializer::make()->fromJson($json, $class::class);
    }

    public function test_union_falls_through_to_next_type_when_scalar_cast_is_lossy(): void
    {
        $class = new class () {
            public float|int $amount;
        };

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('Cannot cast value to any type in union for property amount');

        Deserializer::make()->fromJson('{"amount": "a lot"}', $class::class);
    }
}
