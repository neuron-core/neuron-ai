<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

class ToolPropertyTest extends TestCase
{
    #[DataProvider('coercions')]
    public function test_cast_converts_when_nothing_is_lost(PropertyType $type, mixed $input, mixed $expected): void
    {
        $this->assertSame($expected, (new ToolProperty('value', $type))->cast($input));
    }

    public static function coercions(): array
    {
        return [
            'integer from integer string' => [PropertyType::INTEGER, '5', 5],
            'number from integer string stays an int' => [PropertyType::NUMBER, '5', 5],
            'string from int' => [PropertyType::STRING, 42, '42'],
            'boolean false from string' => [PropertyType::BOOLEAN, 'false', false],
            'null passes through' => [PropertyType::INTEGER, null, null],
            'array type passes through' => [PropertyType::ARRAY, [1, 2], [1, 2]],
            'object type passes through' => [PropertyType::OBJECT, ['id' => 1], ['id' => 1]],
            'null passes through every type' => [PropertyType::BOOLEAN, null, null],
        ];
    }

    #[DataProvider('rejections')]
    public function test_cast_rejects_what_cannot_be_converted(PropertyType $type, mixed $input, string $message): void
    {
        $property = new ToolProperty('value', $type);

        $this->expectException(InvalidToolInput::class);
        $this->expectExceptionMessage($message);

        $property->cast($input);
    }

    public static function rejections(): array
    {
        return [
            'integer from word' => [PropertyType::INTEGER, 'abc', 'must be of type integer, string given'],
            'integer from object' => [PropertyType::INTEGER, new stdClass(), 'must be of type integer, stdClass given'],
            'number from bool' => [PropertyType::NUMBER, true, 'must be of type number, bool given'],
            'string from array' => [PropertyType::STRING, ['a'], 'must be of type string, array given'],
            'boolean from word' => [PropertyType::BOOLEAN, 'maybe', 'must be of type boolean, string given'],
        ];
    }

    public function test_json_schema_omits_absent_metadata(): void
    {
        $this->assertSame(['type' => 'integer'], (new ToolProperty('n', PropertyType::INTEGER))->getJsonSchema());
    }

    public function test_json_schema_carries_description_enum_and_nullability(): void
    {
        $property = new ToolProperty('unit', PropertyType::STRING, 'Temperature unit', true, ['celsius', 'fahrenheit'], true);

        $this->assertSame([
            'type' => ['string', 'null'],
            'description' => 'Temperature unit',
            'enum' => ['celsius', 'fahrenheit'],
        ], $property->getJsonSchema());
    }

    public function test_json_serialization_describes_the_whole_property(): void
    {
        $property = new ToolProperty('unit', PropertyType::STRING, 'Temperature unit', true, ['celsius']);

        $this->assertSame([
            'name' => 'unit',
            'description' => 'Temperature unit',
            'type' => 'string',
            'enum' => ['celsius'],
            'required' => true,
            'nullable' => false,
        ], $property->jsonSerialize());
    }
}
