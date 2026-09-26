<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Filter;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterValidator;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilterValidatorTest extends TestCase
{
    protected function schema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::string('lang')->filterable(),
            DocumentField::integer('year')->filterable(),
            DocumentField::float('price')->filterable(),
            DocumentField::boolean('draft')->filterable(),
            DocumentField::strings('tags')->filterable(),
            DocumentField::integers('years'),
            DocumentField::string('notes'),
        );
    }

    /**
     * @return array<string, array{FilterExpression}>
     */
    public static function validFilters(): array
    {
        return [
            'framework source fields need no declaration' => [FilterGroup::allOf(
                Filter::eq('sourceType', 'file'),
                Filter::neq('sourceName', 'a.txt'),
                Filter::in('sourceName', ['a', 'b']),
            )],
            'neq on a required field' => [Filter::neq('tenant', 'acme')],
            'integer range' => [Filter::gt('year', 2020)],
            'integer value on a float field' => [Filter::lte('price', 10)],
            'float value on a float field' => [Filter::gte('price', 9.99)],
            'boolean equality' => [Filter::eq('draft', false)],
            'string in list' => [Filter::in('lang', ['en', 'it'])],
            'string array containment' => [FilterGroup::anyOf(Filter::containsAny('tags', ['a']), Filter::containsAll('tags', ['a', 'b']))],
            'raw fragments are left to the target store' => [Filter::raw(QdrantVectorStore::class, ['key' => 'anything'])],
        ];
    }

    #[DataProvider('validFilters')]
    public function test_accepts_portable_filters_on_declared_fields(FilterExpression $filters): void
    {
        $this->expectNotToPerformAssertions();

        (new FilterValidator())->validate($filters, $this->schema());
    }

    /**
     * @return array<string, array{FilterExpression, string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'undeclared field' => [Filter::eq('owner', 'me'), 'Filter field "owner" is not declared in the vector store document schema.'],
            'content is not a filter target' => [Filter::eq('content', 'x'), 'Filter field "content" is not declared in the vector store document schema.'],
            'reserved id is not a filter target' => [Filter::eq('id', 'x'), 'Filter field "id" is not declared in the vector store document schema.'],
            'declared but not filterable' => [Filter::eq('notes', 'x'), 'Document field "notes" is not filterable.'],
            'neq on an optional field' => [
                Filter::neq('lang', 'de'),
                'Portable neq filters require field "lang" to be declared required, so missing fields cannot match differently across databases.',
            ],
            'range on a string field' => [Filter::lt('tenant', 5), 'Filter operator lt requires a numeric field; "tenant" is string.'],
            'range on a boolean field' => [Filter::gte('draft', 1), 'Filter operator gte requires a numeric field; "draft" is boolean.'],
            'containment on a scalar field' => [Filter::containsAny('tenant', ['acme']), 'Filter operator contains_any requires a filterable string[] field; "tenant" is string.'],
            'equality on an array field' => [Filter::eq('tags', 'php'), 'Array field "tags" requires contains_any or contains_all.'],
            'in on an array field' => [Filter::in('tags', ['php']), 'Array field "tags" requires contains_any or contains_all.'],
            'string value on an integer field' => [Filter::eq('year', '2026'), 'Filter field "year" expects integer; string given.'],
            'float value on an integer field' => [Filter::gt('year', 2020.5), 'Filter field "year" expects integer; float given.'],
            'integer value on a boolean field' => [Filter::eq('draft', 1), 'Filter field "draft" expects boolean; int given.'],
            'boolean value on a string field' => [Filter::eq('tenant', true), 'Filter field "tenant" expects string; bool given.'],
            'one mistyped value in a list' => [Filter::in('year', [2020, '2021']), 'Filter field "year" expects integer; string given.'],
            'numeric source name' => [Filter::eq('sourceName', 1), 'Filter field "sourceName" expects string; int given.'],
            'invalid condition deep inside a disjunction' => [
                FilterGroup::allOf(
                    Filter::eq('tenant', 'acme'),
                    FilterGroup::anyOf(Filter::eq('lang', 'en'), FilterGroup::allOf(Filter::eq('owner', 'me'))),
                ),
                'Filter field "owner" is not declared in the vector store document schema.',
            ],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_rejects_non_portable_filters(FilterExpression $filters, string $message): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage($message);

        (new FilterValidator())->validate($filters, $this->schema());
    }

    public function test_default_schema_only_allows_source_fields(): void
    {
        $validator = new FilterValidator();
        $validator->validate(Filter::eq('sourceType', 'file'), DocumentSchema::default());

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Filter field "tenant" is not declared in the vector store document schema.');

        $validator->validate(Filter::eq('tenant', 'acme'), DocumentSchema::default());
    }

    public function test_rejects_unknown_expression_types(): void
    {
        $unknown = new class () implements FilterExpression {
            public function toArray(): array
            {
                return [];
            }
        };

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Unsupported filter expression: ');

        (new FilterValidator())->validate($unknown, $this->schema());
    }
}
