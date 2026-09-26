<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Schema;

use NeuronAI\RAG\Schema\DocumentFieldType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;

class DocumentFieldTypeTest extends TestCase
{
    /**
     * @return array<string, array{DocumentFieldType, string, bool, bool, DocumentFieldType}>
     */
    public static function types(): array
    {
        return [
            'string' => [DocumentFieldType::String, 'string', false, false, DocumentFieldType::String],
            'integer' => [DocumentFieldType::Integer, 'integer', true, false, DocumentFieldType::Integer],
            'float' => [DocumentFieldType::Float, 'float', true, false, DocumentFieldType::Float],
            'boolean' => [DocumentFieldType::Boolean, 'boolean', false, false, DocumentFieldType::Boolean],
            'string array' => [DocumentFieldType::StringArray, 'string[]', false, true, DocumentFieldType::String],
            'integer array' => [DocumentFieldType::IntegerArray, 'integer[]', false, true, DocumentFieldType::Integer],
            'float array' => [DocumentFieldType::FloatArray, 'float[]', false, true, DocumentFieldType::Float],
            'boolean array' => [DocumentFieldType::BooleanArray, 'boolean[]', false, true, DocumentFieldType::Boolean],
        ];
    }

    #[DataProvider('types')]
    public function test_type_traits(DocumentFieldType $type, string $value, bool $numeric, bool $array, DocumentFieldType $element): void
    {
        $this->assertSame($value, $type->value);
        $this->assertSame($numeric, $type->isNumeric());
        $this->assertSame($array, $type->isArray());
        $this->assertSame($element, $type->elementType());
    }

    public function test_every_type_is_covered(): void
    {
        $this->assertSame(
            array_map(static fn (array $case): DocumentFieldType => $case[0], array_values(self::types())),
            DocumentFieldType::cases()
        );
    }
}
