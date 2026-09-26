<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Schema;

use ArrayObject;
use JsonException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentFieldType;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use const INF;
use const NAN;

class DocumentSchemaTest extends TestCase
{
    public function test_declared_metadata_is_validated_but_extra_metadata_is_allowed(): void
    {
        $schema = DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::integer('year')->filterable(),
            DocumentField::strings('tags'),
        );

        $document = (new Document('Hello'))
            ->addMetadata('tenant', 'acme')
            ->addMetadata('year', 2026)
            ->addMetadata('tags', ['php'])
            ->addMetadata('custom', ['nested' => true]);

        $schema->validate($document);

        $this->assertSame(['nested' => true], $document->getMetadata()['custom']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidStringArrays(): array
    {
        return [
            'empty list' => [[]],
            'associative array' => [['primary' => 'php']],
            'list with a gap' => [[0 => 'php', 2 => 'rag']],
            'mixed element types' => [['php', 1]],
            'nested list' => [[['php']]],
            'scalar' => ['php'],
        ];
    }

    #[DataProvider('invalidStringArrays')]
    public function test_declared_arrays_must_be_non_empty_lists_of_their_element_type(mixed $tags): void
    {
        $schema = DocumentSchema::of(DocumentField::strings('tags'));

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('metadata field "tags" expects string[];');

        $schema->validate((new Document('Hello'))->addMetadata('tags', $tags));
    }

    public function test_unknown_filter_expressions_are_rejected(): void
    {
        $store = new MemoryVectorStore();
        $expression = new class () implements FilterExpression {
            public function toArray(): array
            {
                return ['operator' => 'custom'];
            }
        };

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Unsupported filter expression');

        $store->search(new SearchRequest([1, 0], $expression));
    }

    public function test_required_field_must_exist(): void
    {
        $schema = DocumentSchema::of(DocumentField::string('tenant')->required());

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('missing required metadata field "tenant"');

        $schema->validate(new Document('Hello'));
    }

    public function test_declared_field_must_match_its_type(): void
    {
        $schema = DocumentSchema::of(DocumentField::integer('year'));

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('expects integer; string given');

        $schema->validate((new Document('Hello'))->addMetadata('year', '2026'));
    }

    public function test_reserved_metadata_names_are_rejected(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('reserved by the framework');

        (new Document('Hello'))->addMetadata('score', 0.9);
    }

    public function test_custom_filter_requires_a_declared_filterable_field(): void
    {
        $document = (new Document('Hello'))->setEmbedding([1, 0]);
        $store = new MemoryVectorStore();
        $store->addDocument($document);

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('not declared');

        $store->search(new SearchRequest(
            [1, 0],
            FilterGroup::and(Filter::eq('tenant', 'acme')),
        ));
    }

    public function test_filter_values_are_checked_against_the_schema(): void
    {
        $schema = DocumentSchema::of(DocumentField::integer('year')->filterable());
        $store = new MemoryVectorStore(schema: $schema);

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('expects integer; string given');

        $store->search(new SearchRequest(
            [1, 0],
            FilterGroup::and(Filter::eq('year', '2026')),
        ));
    }

    public function test_neq_requires_a_required_field(): void
    {
        $schema = DocumentSchema::of(DocumentField::string('tenant')->filterable());
        $store = new MemoryVectorStore(schema: $schema);

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('to be declared required');

        $store->search(new SearchRequest(
            [1, 0],
            FilterGroup::and(Filter::neq('tenant', 'acme')),
        ));
    }

    public function test_filterable_string_arrays_support_portable_containment(): void
    {
        $schema = DocumentSchema::of(DocumentField::strings('tags')->filterable());
        $store = new MemoryVectorStore(schema: $schema);
        $store->addDocument(
            (new Document('Hello'))
                ->setEmbedding([1, 0])
                ->addMetadata('tags', ['php', 'rag'])
        );

        $results = $store->search(new SearchRequest([1, 0], Filter::containsAll('tags', ['php', 'rag'])));

        $this->assertCount(1, $results);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reservedFields(): array
    {
        $cases = [];
        foreach (DocumentSchema::RESERVED_FIELDS as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    #[DataProvider('reservedFields')]
    public function test_reserved_field_names_cannot_be_declared(string $name): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage("Document metadata field \"{$name}\" is reserved by the framework.");

        DocumentSchema::of(DocumentField::string($name));
    }

    public function test_a_field_cannot_be_declared_twice(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Document metadata field "year" is declared more than once.');

        DocumentSchema::of(DocumentField::integer('year'), DocumentField::string('year'));
    }

    public function test_fields_are_listed_in_declaration_order_and_looked_up_by_name(): void
    {
        $tenant = DocumentField::string('tenant');
        $year = DocumentField::integer('year');

        $schema = DocumentSchema::of($tenant, $year);

        $this->assertSame([$tenant, $year], $schema->fields());
        $this->assertSame($year, $schema->getField('year'));
        $this->assertNull($schema->getField('Year'));
        $this->assertSame([], DocumentSchema::default()->fields());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function builtInSourceFields(): array
    {
        return [
            'source type' => ['sourceType'],
            'source name' => ['sourceName'],
        ];
    }

    #[DataProvider('builtInSourceFields')]
    public function test_source_fields_are_always_required_filterable_strings(string $name): void
    {
        $field = DocumentSchema::default()->requireFilterableField($name);

        $this->assertSame($name, $field->getName());
        $this->assertSame(DocumentFieldType::String, $field->getType());
        $this->assertTrue($field->isRequired());
        $this->assertTrue($field->isFilterable());
    }

    public function test_undeclared_filter_field_is_rejected(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Filter field "tenant" is not declared in the vector store document schema.');

        DocumentSchema::default()->requireFilterableField('tenant');
    }

    public function test_declared_but_not_filterable_field_is_rejected_as_filter(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Document field "tenant" is not filterable.');

        DocumentSchema::of(DocumentField::string('tenant'))->requireFilterableField('tenant');
    }

    public function test_declared_filterable_field_is_returned_for_filtering(): void
    {
        $tenant = DocumentField::string('tenant')->filterable();

        $this->assertSame($tenant, DocumentSchema::of($tenant)->requireFilterableField('tenant'));
    }

    public function test_optional_field_may_be_missing_or_null(): void
    {
        $schema = DocumentSchema::of(DocumentField::integer('year'));

        $schema->validate(new Document('Hello'));
        $schema->validate((new Document('Hello'))->addMetadata('year', null));

        $this->addToAssertionCount(2);
    }

    public function test_required_field_set_to_null_counts_as_missing(): void
    {
        $schema = DocumentSchema::of(DocumentField::string('tenant')->required());

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Document doc-1 is missing required metadata field "tenant".');

        $schema->validate((new Document('Hello'))->setId('doc-1')->addMetadata('tenant', null));
    }

    /**
     * @return array<string, array{DocumentField, mixed, string}>
     */
    public static function mismatchedValues(): array
    {
        return [
            'numeric string for a float' => [DocumentField::float('price'), '9.99', 'float; string'],
            'boolean for a float' => [DocumentField::float('price'), true, 'float; bool'],
            'integral float for an integer' => [DocumentField::integer('year'), 2026.0, 'integer; float'],
            'map for a string array' => [DocumentField::strings('tags'), ['main' => 'php'], 'string[]; array'],
        ];
    }

    #[DataProvider('mismatchedValues')]
    public function test_type_mismatch_names_document_field_expected_and_given_types(DocumentField $field, mixed $value, string $types): void
    {
        $schema = DocumentSchema::of($field);

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage("Document doc-1 metadata field \"{$field->getName()}\" expects {$types} given.");

        $schema->validate((new Document('Hello'))->setId('doc-1')->addMetadata($field->getName(), $value));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function unsafeMetadataValues(): array
    {
        return [
            'object' => [new stdClass(), 'stdClass'],
            'json serializable object' => [new ArrayObject(['a' => 1]), 'ArrayObject'],
            'object nested in an array' => [['nested' => [new stdClass()]], 'array'],
            'closure' => [static fn (): int => 1, 'Closure'],
        ];
    }

    #[DataProvider('unsafeMetadataValues')]
    public function test_undeclared_metadata_must_be_json_safe_data(mixed $value, string $type): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage(
            "Document doc-1 metadata field \"custom\" must contain JSON-safe scalar, array, or null values; {$type} given."
        );

        DocumentSchema::default()->validate((new Document('Hello'))->setId('doc-1')->addMetadata('custom', $value));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unencodableMetadataValues(): array
    {
        return [
            'invalid utf-8' => ["\xB1\x31"],
            'not a number' => [NAN],
            'infinity' => [INF],
        ];
    }

    #[DataProvider('unencodableMetadataValues')]
    public function test_metadata_must_be_json_serializable(mixed $value): void
    {
        try {
            DocumentSchema::default()->validate((new Document('Hello'))->setId('doc-1')->addMetadata('custom', $value));
            $this->fail('Metadata that cannot be stored as JSON was accepted.');
        } catch (DocumentSchemaException $exception) {
            $this->assertStringStartsWith('Document doc-1 metadata is not JSON serializable: ', $exception->getMessage());
            $this->assertInstanceOf(JsonException::class, $exception->getPrevious());
        }
    }

    /**
     * @return array<string, array{DocumentFieldType, mixed, bool}>
     */
    public static function typedValues(): array
    {
        return [
            'string accepts string' => [DocumentFieldType::String, 'acme', true],
            'string accepts empty string' => [DocumentFieldType::String, '', true],
            'string rejects integer' => [DocumentFieldType::String, 1, false],
            'integer accepts integer' => [DocumentFieldType::Integer, 2026, true],
            'integer rejects integral float' => [DocumentFieldType::Integer, 2026.0, false],
            'integer rejects numeric string' => [DocumentFieldType::Integer, '2026', false],
            'integer rejects boolean' => [DocumentFieldType::Integer, true, false],
            'float accepts float' => [DocumentFieldType::Float, 9.99, true],
            'float accepts integer' => [DocumentFieldType::Float, 10, true],
            'float rejects numeric string' => [DocumentFieldType::Float, '9.99', false],
            'boolean accepts false' => [DocumentFieldType::Boolean, false, true],
            'boolean rejects integer' => [DocumentFieldType::Boolean, 0, false],
            'boolean rejects string' => [DocumentFieldType::Boolean, 'true', false],
            'strings accepts list of strings' => [DocumentFieldType::StringArray, ['php', ''], true],
            'strings rejects list with integer' => [DocumentFieldType::StringArray, ['php', 1], false],
            'integers accepts list of integers' => [DocumentFieldType::IntegerArray, [1, 2], true],
            'integers rejects list with float' => [DocumentFieldType::IntegerArray, [1, 2.0], false],
            'floats accepts integers and floats' => [DocumentFieldType::FloatArray, [1, 2.5], true],
            'floats rejects list with string' => [DocumentFieldType::FloatArray, [1.5, '2'], false],
            'booleans accepts list of booleans' => [DocumentFieldType::BooleanArray, [true, false], true],
            'booleans rejects list with integer' => [DocumentFieldType::BooleanArray, [true, 1], false],
            'arrays reject empty list' => [DocumentFieldType::IntegerArray, [], false],
            'arrays reject associative array' => [DocumentFieldType::BooleanArray, ['a' => true], false],
            'arrays reject scalar' => [DocumentFieldType::FloatArray, 1.5, false],
            'scalars reject arrays' => [DocumentFieldType::String, ['acme'], false],
            'scalars reject null' => [DocumentFieldType::Boolean, null, false],
        ];
    }

    #[DataProvider('typedValues')]
    public function test_value_matches_its_declared_type(DocumentFieldType $type, mixed $value, bool $matches): void
    {
        $this->assertSame($matches, DocumentSchema::default()->valueMatches($type, $value));
    }
}
