<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;

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
            ->addMetadata('tags', [])
            ->addMetadata('custom', ['nested' => true]);

        $schema->validate($document);

        $this->assertSame(['nested' => true], $document->getMetadata()['custom']);
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

    public function test_schema_field_names_must_be_portable_identifiers(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('letters, numbers, or underscores');

        DocumentField::string('tenant.id');
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

    public function test_non_string_arrays_remain_native_filter_only(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Only string arrays');

        DocumentField::integers('years')->filterable();
    }
}
