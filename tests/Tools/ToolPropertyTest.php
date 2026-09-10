<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
            'number from float' => [PropertyType::NUMBER, 2.5, 2.5],
            'number from decimal string' => [PropertyType::NUMBER, '2.5', 2.5],
            'number from integer string stays an int' => [PropertyType::NUMBER, '5', 5],
            'number from scientific string' => [PropertyType::NUMBER, '1e3', 1000.0],
            'string from string' => [PropertyType::STRING, 'abc', 'abc'],
            'string from int' => [PropertyType::STRING, 42, '42'],
            'boolean from bool' => [PropertyType::BOOLEAN, false, false],
            'boolean from string' => [PropertyType::BOOLEAN, 'true', true],
            'null passes through' => [PropertyType::INTEGER, null, null],
            'array type passes through' => [PropertyType::ARRAY, [1, 2], [1, 2]],
        ];
    }

    #[DataProvider('rejections')]
    public function test_cast_rejects_what_cannot_be_converted(PropertyType $type, mixed $input, string $message): void
    {
        $this->expectException(InvalidToolInput::class);
        $this->expectExceptionMessage($message);

        (new ToolProperty('value', $type))->cast($input);
    }

    public static function rejections(): array
    {
        return [
            'integer from word' => [PropertyType::INTEGER, 'abc', 'must be of type integer, string given'],
            'integer from fractional float' => [PropertyType::INTEGER, 5.5, 'must be of type integer, float given'],
            'integer from bool' => [PropertyType::INTEGER, true, 'must be of type integer, bool given'],
            'integer from array' => [PropertyType::INTEGER, [5], 'must be of type integer, array given'],
            'number from word' => [PropertyType::NUMBER, 'abc', 'must be of type number, string given'],
            'string from array' => [PropertyType::STRING, ['a'], 'must be of type string, array given'],
            'string from bool' => [PropertyType::STRING, false, 'must be of type string, bool given'],
            'boolean from word' => [PropertyType::BOOLEAN, 'maybe', 'must be of type boolean, string given'],
            'boolean from array' => [PropertyType::BOOLEAN, [true], 'must be of type boolean, array given'],
        ];
    }
}
