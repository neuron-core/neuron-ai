<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Schema;

use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentFieldType;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentFieldTest extends TestCase
{
    /**
     * @return array<string, array{DocumentField, DocumentFieldType}>
     */
    public static function factories(): array
    {
        return [
            'string' => [DocumentField::string('field'), DocumentFieldType::String],
            'integer' => [DocumentField::integer('field'), DocumentFieldType::Integer],
            'float' => [DocumentField::float('field'), DocumentFieldType::Float],
            'boolean' => [DocumentField::boolean('field'), DocumentFieldType::Boolean],
            'strings' => [DocumentField::strings('field'), DocumentFieldType::StringArray],
            'integers' => [DocumentField::integers('field'), DocumentFieldType::IntegerArray],
            'floats' => [DocumentField::floats('field'), DocumentFieldType::FloatArray],
            'booleans' => [DocumentField::booleans('field'), DocumentFieldType::BooleanArray],
        ];
    }

    #[DataProvider('factories')]
    public function test_factories_declare_an_optional_non_filterable_field_of_their_type(DocumentField $field, DocumentFieldType $type): void
    {
        $this->assertSame('field', $field->getName());
        $this->assertSame($type, $field->getType());
        $this->assertFalse($field->isRequired());
        $this->assertFalse($field->isFilterable());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function portableNames(): array
    {
        return [
            'lowercase' => ['tenant'],
            'leading underscore' => ['_private'],
            'mixed case with digits' => ['Tenant_ID2'],
            'single letter' => ['x'],
        ];
    }

    #[DataProvider('portableNames')]
    public function test_portable_identifiers_are_accepted_as_names(string $name): void
    {
        $this->assertSame($name, DocumentField::string($name)->getName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unportableNames(): array
    {
        return [
            'empty' => [''],
            'leading digit' => ['1tenant'],
            'dot path' => ['tenant.id'],
            'dash' => ['tenant-id'],
            'space' => ['tenant id'],
            'quote' => ["tenant'"],
            'sql comment' => ['tenant--'],
            'filter dsl operator' => ['tenant:=x'],
            'json path' => ['$.tenant'],
            'non ascii letter' => ['città'],
            'leading newline' => ["\ntenant"],
            'null byte' => ["tenant\0"],
        ];
    }

    #[DataProvider('unportableNames')]
    public function test_unportable_names_are_rejected(string $name): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('must start with a letter or underscore and contain letters, numbers, or underscores only.');

        DocumentField::string($name);
    }

    public function test_required_returns_a_modified_copy(): void
    {
        $optional = DocumentField::string('tenant');

        $required = $optional->required();

        $this->assertNotSame($optional, $required);
        $this->assertTrue($required->isRequired());
        $this->assertFalse($optional->isRequired());
        $this->assertFalse($required->required(false)->isRequired());
    }

    public function test_filterable_returns_a_modified_copy_and_keeps_other_flags(): void
    {
        $required = DocumentField::integer('year')->required();

        $filterable = $required->filterable();

        $this->assertNotSame($required, $filterable);
        $this->assertTrue($filterable->isFilterable());
        $this->assertTrue($filterable->isRequired());
        $this->assertFalse($required->isFilterable());
        $this->assertFalse($filterable->filterable(false)->isFilterable());
    }

    /**
     * @return array<string, array{DocumentField, string}>
     */
    public static function nativeOnlyArrays(): array
    {
        return [
            'integers' => [DocumentField::integers('years'), 'integer[]'],
            'floats' => [DocumentField::floats('ratings'), 'float[]'],
            'booleans' => [DocumentField::booleans('flags'), 'boolean[]'],
        ];
    }

    #[DataProvider('nativeOnlyArrays')]
    public function test_only_string_arrays_can_be_filterable(DocumentField $field, string $type): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage(
            "Only string arrays support portable filters; field \"{$field->getName()}\" is {$type}. Use a raw backend filter instead."
        );

        $field->filterable();
    }

    #[DataProvider('nativeOnlyArrays')]
    public function test_non_string_arrays_can_be_explicitly_marked_not_filterable(DocumentField $field): void
    {
        $this->assertFalse($field->filterable(false)->isFilterable());
    }

    public function test_string_arrays_and_scalars_can_be_filterable(): void
    {
        $this->assertTrue(DocumentField::strings('tags')->filterable()->isFilterable());
        $this->assertTrue(DocumentField::boolean('published')->filterable()->isFilterable());
        $this->assertTrue(DocumentField::float('price')->filterable()->isFilterable());
    }
}
