<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Deserializer;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\Tests\StructuredOutput\Stub\StringEnum;
use PHPUnit\Framework\TestCase;

class SingleEnumArrayTest extends TestCase
{
    public function test_array_of_enum_values_advertised_by_the_schema_is_deserialized(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [StringEnum::class])]
            public array $numbers;
        };

        $items = JsonSchema::make()->generate($class::class)['properties']['numbers']['items'];
        $this->assertSame(['type' => 'string', 'enum' => ['one', 'two', 'three']], $items);

        $obj = Deserializer::make()->fromJson('{"numbers": ["one", "three"]}', $class::class);

        $this->assertSame([StringEnum::ONE, StringEnum::THREE], $obj->numbers);
    }

    public function test_invalid_enum_value_in_array_raises_a_deserializer_exception(): void
    {
        $class = new class () {
            #[SchemaProperty(anyOf: [StringEnum::class])]
            public array $numbers;
        };

        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage("Invalid enum value 'four'");

        Deserializer::make()->fromJson('{"numbers": ["one", "four"]}', $class::class);
    }
}
