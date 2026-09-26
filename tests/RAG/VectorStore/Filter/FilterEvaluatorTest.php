<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Filter;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterEvaluator;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilterEvaluatorTest extends TestCase
{
    protected FilterEvaluator $evaluator;

    /**
     * @var array<string, mixed>
     */
    protected array $fields = [
        'sourceType' => 'file',
        'sourceName' => 'doc.txt',
        'year' => 2024,
        'reviewed' => true,
    ];

    protected function setUp(): void
    {
        $this->evaluator = new FilterEvaluator();
    }

    public function test_all_conditions_must_match(): void
    {
        $matching = FilterGroup::and(
            Filter::eq('sourceType', 'file'),
            Filter::gte('year', 2020),
        );
        $failing = FilterGroup::and(
            Filter::eq('sourceType', 'file'),
            Filter::gte('year', 2025),
        );

        $this->assertTrue($this->evaluator->matches($matching, $this->fields));
        $this->assertFalse($this->evaluator->matches($failing, $this->fields));
    }

    public function test_neq_and_in(): void
    {
        $this->assertTrue($this->evaluator->matches(
            FilterGroup::and(Filter::neq('sourceType', 'web')),
            $this->fields
        ));
        $this->assertTrue($this->evaluator->matches(
            FilterGroup::and(Filter::in('sourceType', ['web', 'file'])),
            $this->fields
        ));
        $this->assertFalse($this->evaluator->matches(
            FilterGroup::and(Filter::in('sourceType', ['web', 'api'])),
            $this->fields
        ));
    }

    public function test_range_operators(): void
    {
        $this->assertTrue($this->evaluator->matches(
            FilterGroup::and(Filter::gt('year', 2023), Filter::lt('year', 2025)),
            $this->fields
        ));
        $this->assertFalse($this->evaluator->matches(
            FilterGroup::and(Filter::lte('year', 2023)),
            $this->fields
        ));
    }

    public function test_missing_field_never_matches_even_neq(): void
    {
        $this->assertFalse($this->evaluator->matches(
            FilterGroup::and(Filter::eq('tenant', 'acme')),
            $this->fields
        ));
        $this->assertFalse($this->evaluator->matches(
            FilterGroup::and(Filter::neq('tenant', 'acme')),
            $this->fields
        ));
    }

    public function test_numeric_values_compare_loosely(): void
    {
        $this->assertTrue($this->evaluator->matches(
            FilterGroup::and(Filter::eq('year', 2024.0)),
            $this->fields
        ));
    }

    public function test_numeric_strings_compare_strictly(): void
    {
        $this->assertFalse($this->evaluator->matches(
            Filter::eq('tenant', '0e12345'),
            ['tenant' => '0e67890'],
        ));
    }

    public function test_boolean_values_compare_strictly(): void
    {
        $this->assertTrue($this->evaluator->matches(
            FilterGroup::and(Filter::eq('reviewed', true)),
            $this->fields
        ));
        $this->assertFalse($this->evaluator->matches(
            FilterGroup::and(Filter::eq('reviewed', false)),
            $this->fields
        ));
    }

    public function test_raw_filters_cannot_be_evaluated(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Raw filter targets ' . MeilisearchVectorStore::class . '; it cannot be evaluated in PHP.');

        $this->evaluator->matches(
            FilterGroup::and(Filter::raw(MeilisearchVectorStore::class, "sourceType = 'file'")),
            $this->fields
        );
    }

    public function test_nested_or_groups_are_evaluated(): void
    {
        $filters = FilterGroup::allOf(
            Filter::eq('sourceType', 'file'),
            FilterGroup::anyOf(
                Filter::eq('year', 2025),
                Filter::eq('reviewed', true),
            ),
        );

        $this->assertTrue($this->evaluator->matches($filters, $this->fields));
    }

    public function test_string_array_containment_is_evaluated(): void
    {
        $fields = [...$this->fields, 'tags' => ['php', 'rag', 'agents']];

        $this->assertTrue($this->evaluator->matches(Filter::containsAny('tags', ['ai', 'rag']), $fields));
        $this->assertTrue($this->evaluator->matches(Filter::containsAll('tags', ['php', 'agents']), $fields));
        $this->assertFalse($this->evaluator->matches(Filter::containsAll('tags', ['php', 'missing']), $fields));
    }

    public function test_any_of_matches_when_one_branch_matches_and_fails_when_none_do(): void
    {
        $this->assertTrue($this->evaluator->matches(
            FilterGroup::anyOf(Filter::eq('sourceType', 'web'), Filter::eq('year', 2024)),
            $this->fields,
        ));
        $this->assertFalse($this->evaluator->matches(
            FilterGroup::anyOf(Filter::eq('sourceType', 'web'), Filter::eq('year', 2025)),
            $this->fields,
        ));
    }

    /**
     * @return array<string, array{Filter, bool}>
     */
    public static function comparisons(): array
    {
        return [
            'gt at the boundary' => [Filter::gt('year', 2024), false],
            'gte at the boundary' => [Filter::gte('year', 2024), true],
            'lt at the boundary' => [Filter::lt('year', 2024), false],
            'lte at the boundary' => [Filter::lte('year', 2024), true],
            'float range against an integer' => [Filter::gt('year', 2023.5), true],
            'negative range' => [Filter::gt('year', -1), true],
            'in with a loosely equal float' => [Filter::in('year', [1999, 2024.0]), true],
            'in never matches a numeric string with an integer' => [Filter::in('code', [7, 42]), false],
            'in keeps numeric looking strings distinct' => [Filter::in('code', ['42.0', '4.2e1']), false],
            'in never matches a boolean with an integer' => [Filter::in('reviewed', [0, 1]), false],
            'integer never equals a numeric string' => [Filter::eq('code', 42), false],
            'numeric string never equals an integer' => [Filter::eq('year', '2024'), false],
            'boolean never equals an integer' => [Filter::eq('reviewed', 1), false],
            'string comparison is case sensitive' => [Filter::eq('sourceType', 'FILE'), false],
            'string comparison keeps whitespace' => [Filter::eq('sourceType', 'file '), false],
            'multibyte strings compare exactly' => [Filter::eq('title', 'Città'), true],
            'range on a non numeric string' => [Filter::gt('sourceType', 1), false],
            'range on a boolean' => [Filter::gte('reviewed', 0), false],
            'neq on a different value' => [Filter::neq('sourceType', 'web'), true],
            'neq on the same value' => [Filter::neq('sourceType', 'file'), false],
            'containment on a scalar field' => [Filter::containsAny('sourceType', ['file']), false],
            'contains any without overlap' => [Filter::containsAny('tags', ['go', 'rust']), false],
            'contains all with duplicates' => [Filter::containsAll('tags', ['php', 'php']), true],
            'containment is strict' => [Filter::containsAny('tags', ['PHP']), false],
        ];
    }

    #[DataProvider('comparisons')]
    public function test_comparison_semantics(Filter $filter, bool $expected): void
    {
        $fields = [...$this->fields, 'code' => '42', 'title' => 'Città', 'tags' => ['php', 'rag']];

        $this->assertSame($expected, $this->evaluator->matches($filter, $fields));
    }

    public function test_documents_expose_source_content_and_metadata_as_fields(): void
    {
        $document = (new Document('Body'))
            ->setSourceType('file')
            ->setSourceName('a.txt')
            ->setMetadata(['tenant' => 'acme', 'tags' => ['php']]);

        $this->assertTrue($this->evaluator->matchesDocument(FilterGroup::allOf(
            Filter::eq('sourceType', 'file'),
            Filter::eq('sourceName', 'a.txt'),
            Filter::eq('content', 'Body'),
            Filter::eq('tenant', 'acme'),
            Filter::containsAll('tags', ['php']),
        ), $document));
        $this->assertFalse($this->evaluator->matchesDocument(Filter::eq('tenant', 'globex'), $document));
    }

    public function test_unknown_expressions_fail_closed(): void
    {
        $unknown = new class () implements FilterExpression {
            public function toArray(): array
            {
                return [];
            }
        };

        $this->assertFalse($this->evaluator->matches($unknown, $this->fields));
        $this->assertFalse($this->evaluator->matches(FilterGroup::anyOf($unknown), $this->fields));
    }
}
