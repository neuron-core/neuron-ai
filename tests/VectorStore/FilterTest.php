<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use DateTimeImmutable;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\FilterScope;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use PHPUnit\Framework\TestCase;
use TypeError;

class FilterTest extends TestCase
{
    public function test_fluent_criteria_builds_a_conjunction(): void
    {
        $filters = Filter::where('tenant', 'acme')
            ->whereIn('status', ['published', 'reviewed'])
            ->whereGreaterThanOrEqual('year', 2020);

        $conditions = $filters->conditions();
        $this->assertCount(3, $conditions);
        $first = $conditions[0];
        $second = $conditions[1];
        $third = $conditions[2];
        $this->assertInstanceOf(Filter::class, $first);
        $this->assertInstanceOf(Filter::class, $second);
        $this->assertInstanceOf(Filter::class, $third);
        $this->assertSame(FilterOperator::Eq, $first->operator);
        $this->assertSame(FilterOperator::In, $second->operator);
        $this->assertSame(FilterOperator::Gte, $third->operator);
    }

    public function test_filters_accept_schema_fields_and_normalize_domain_values(): void
    {
        $status = DocumentField::string('status')->filterable();
        $publishedAt = DocumentField::integer('published_at')->filterable();

        $filters = Filter::where($status, FilterStatus::Published)
            ->whereGreaterThanOrEqual($publishedAt, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $conditions = $filters->conditions();
        $statusFilter = $conditions[0];
        $dateFilter = $conditions[1];
        $this->assertInstanceOf(Filter::class, $statusFilter);
        $this->assertInstanceOf(Filter::class, $dateFilter);
        $this->assertSame('published', $statusFilter->value);
        $this->assertSame(1767225600, $dateFilter->value);
    }

    public function test_groups_support_nested_any_of_expressions(): void
    {
        $filters = FilterGroup::allOf(
            Filter::eq('tenant', 'acme'),
            FilterGroup::anyOf(
                Filter::eq('published', true),
                Filter::eq('owner', 'user-1'),
            ),
        );

        $this->assertSame('and', $filters->operator()->value);
        $nested = $filters->conditions()[1];
        $this->assertInstanceOf(FilterGroup::class, $nested);
        $this->assertSame('or', $nested->operator()->value);
    }

    public function test_scopes_always_merge_at_the_root_with_and(): void
    {
        $scope = FilterScope::merge(
            Filter::eq('tenant', 'acme'),
            FilterGroup::anyOf(Filter::eq('published', true), Filter::eq('owner', 'user-1')),
        );

        $this->assertNotNull($scope);
        $expression = $scope->expression();
        $this->assertInstanceOf(FilterGroup::class, $expression);
        $this->assertSame('and', $expression->operator()->value);
        $nested = $expression->conditions()[1];
        $this->assertInstanceOf(FilterGroup::class, $nested);
        $this->assertSame('or', $nested->operator()->value);
    }

    public function test_conditions_carry_field_operator_and_value(): void
    {
        $filter = Filter::eq('sourceType', 'file');

        $this->assertSame('sourceType', $filter->field);
        $this->assertSame(FilterOperator::Eq, $filter->operator);
        $this->assertSame('file', $filter->value);
    }

    public function test_empty_field_is_rejected(): void
    {
        $this->expectException(VectorStoreException::class);

        Filter::eq('', 'value');
    }

    public function test_null_value_is_rejected(): void
    {
        $this->expectException(TypeError::class);

        /** @phpstan-ignore-next-line deliberately wrong type */
        Filter::eq('field', null);
    }

    public function test_string_range_value_is_rejected(): void
    {
        $this->expectException(TypeError::class);

        /** @phpstan-ignore-next-line deliberately wrong type */
        Filter::gte('published_at', '2026-01-01');
    }

    public function test_empty_in_is_rejected(): void
    {
        $this->expectException(VectorStoreException::class);

        Filter::in('sourceType', []);
    }

    public function test_non_scalar_in_value_is_rejected(): void
    {
        $this->expectException(VectorStoreException::class);

        /** @phpstan-ignore-next-line deliberately wrong type */
        Filter::in('sourceType', ['file', null]);
    }

    public function test_group_requires_at_least_one_condition(): void
    {
        $this->expectException(VectorStoreException::class);

        FilterGroup::and();
    }

    public function test_nested_groups_flatten_into_one_conjunction(): void
    {
        $scope = FilterGroup::and(Filter::eq('tenant', 'acme'));
        $user = FilterGroup::and(Filter::eq('lang', 'en'), Filter::gte('year', 2020));

        $merged = FilterGroup::and($scope, $user);

        $this->assertCount(3, $merged->conditions());
    }

    public function test_raw_is_tagged_with_its_target_store(): void
    {
        $raw = Filter::raw(MeilisearchVectorStore::class, "sourceType = 'file'");

        $this->assertInstanceOf(RawFilter::class, $raw);
        $this->assertSame(MeilisearchVectorStore::class, $raw->store);
        $this->assertSame("sourceType = 'file'", $raw->fragment);
    }
}

enum FilterStatus: string
{
    case Published = 'published';
}
