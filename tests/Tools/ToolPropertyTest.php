<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use const INF;
use const NAN;
use const PHP_INT_MAX;

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
            'integer from int' => [PropertyType::INTEGER, 5, 5],
            'integer from integer string' => [PropertyType::INTEGER, '5', 5],
            'integer from integral float' => [PropertyType::INTEGER, 5.0, 5],
            'integer from signed string' => [PropertyType::INTEGER, '+5', 5],
            'integer from padded string' => [PropertyType::INTEGER, " -7\n", -7],
            'integer at the upper bound' => [PropertyType::INTEGER, '9223372036854775807', PHP_INT_MAX],
            'integer zero' => [PropertyType::INTEGER, '0', 0],
            'number from float' => [PropertyType::NUMBER, 2.5, 2.5],
            'number from decimal string' => [PropertyType::NUMBER, '2.5', 2.5],
            'number from integer string stays an int' => [PropertyType::NUMBER, '5', 5],
            'number from scientific string' => [PropertyType::NUMBER, '1e3', 1000.0],
            'number from negative decimal string' => [PropertyType::NUMBER, '-0.5', -0.5],
            'number from int' => [PropertyType::NUMBER, 7, 7],
            'string from string' => [PropertyType::STRING, 'abc', 'abc'],
            'string from int' => [PropertyType::STRING, 42, '42'],
            'string from float' => [PropertyType::STRING, 1.5, '1.5'],
            'empty string stays empty' => [PropertyType::STRING, '', ''],
            'multibyte string untouched' => [PropertyType::STRING, 'caffè ☕', 'caffè ☕'],
            'boolean from bool' => [PropertyType::BOOLEAN, false, false],
            'boolean from string' => [PropertyType::BOOLEAN, 'true', true],
            'boolean false from string' => [PropertyType::BOOLEAN, 'false', false],
            'boolean case insensitive' => [PropertyType::BOOLEAN, 'TRUE', true],
            'boolean from one' => [PropertyType::BOOLEAN, 1, true],
            'boolean from zero string' => [PropertyType::BOOLEAN, '0', false],
            'null passes through' => [PropertyType::INTEGER, null, null],
            'array type passes through' => [PropertyType::ARRAY, [1, 2], [1, 2]],
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
            'integer from fractional float' => [PropertyType::INTEGER, 5.5, 'must be of type integer, float given'],
            'integer from bool' => [PropertyType::INTEGER, true, 'must be of type integer, bool given'],
            'integer from array' => [PropertyType::INTEGER, [5], 'must be of type integer, array given'],
            'integer from decimal string' => [PropertyType::INTEGER, '5.0', 'must be of type integer, string given'],
            'integer from hexadecimal string' => [PropertyType::INTEGER, '0x1A', 'must be of type integer, string given'],
            'integer from scientific string' => [PropertyType::INTEGER, '1e3', 'must be of type integer, string given'],
            'integer beyond the int range' => [PropertyType::INTEGER, '9223372036854775808', 'must be of type integer, string given'],
            'integer from float beyond the int range' => [PropertyType::INTEGER, 1e20, 'must be of type integer, float given'],
            'integer from empty string' => [PropertyType::INTEGER, '', 'must be of type integer, string given'],
            'integer from object' => [PropertyType::INTEGER, new stdClass(), 'must be of type integer, stdClass given'],
            'number from word' => [PropertyType::NUMBER, 'abc', 'must be of type number, string given'],
            'number from bool' => [PropertyType::NUMBER, true, 'must be of type number, bool given'],
            'number from localized decimal' => [PropertyType::NUMBER, '1,5', 'must be of type number, string given'],
            'number from infinity' => [PropertyType::NUMBER, INF, 'must be of type number, float given'],
            'number from not-a-number' => [PropertyType::NUMBER, NAN, 'must be of type number, float given'],
            'number overflowing a double' => [PropertyType::NUMBER, '1e400', 'must be of type number, string given'],
            'string from array' => [PropertyType::STRING, ['a'], 'must be of type string, array given'],
            'string from bool' => [PropertyType::STRING, false, 'must be of type string, bool given'],
            'string from object' => [PropertyType::STRING, new stdClass(), 'must be of type string, stdClass given'],
            'boolean from word' => [PropertyType::BOOLEAN, 'maybe', 'must be of type boolean, string given'],
            'boolean from array' => [PropertyType::BOOLEAN, [true], 'must be of type boolean, array given'],
            'boolean from two' => [PropertyType::BOOLEAN, 2, 'must be of type boolean, int given'],
            'boolean from null word' => [PropertyType::BOOLEAN, 'null', 'must be of type boolean, string given'],
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
