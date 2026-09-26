<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Filter;

use DateTimeImmutable;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Criteria;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterCombinator;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\FilterScope;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\Tests\RAG\VectorStore\Filter\Stub\FilterStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;
use Closure;
use Throwable;

class FilterTest extends TestCase
{
    public function test_fluent_criteria_builds_a_conjunction(): void
    {
        $filters = Filter::where('tenant', 'acme')
            ->whereIn('status', ['published', 'reviewed'])
            ->whereGreaterThanOrEqual('year', 2020);

        $this->assertSame([
            'operator' => 'and',
            'conditions' => [
                ['operator' => 'eq', 'field' => 'tenant', 'value' => 'acme'],
                ['operator' => 'in', 'field' => 'status', 'value' => ['published', 'reviewed']],
                ['operator' => 'gte', 'field' => 'year', 'value' => 2020],
            ],
        ], $filters->toArray());
    }

    /**
     * @return array<string, array{Closure(Criteria): Criteria, array<string, mixed>}>
     */
    public static function criteriaMethods(): array
    {
        return [
            'whereNot' => [static fn (Criteria $c): Criteria => $c->whereNot('lang', 'de'), ['operator' => 'neq', 'field' => 'lang', 'value' => 'de']],
            'whereGreaterThan' => [static fn (Criteria $c): Criteria => $c->whereGreaterThan('year', 2020), ['operator' => 'gt', 'field' => 'year', 'value' => 2020]],
            'whereLessThan' => [static fn (Criteria $c): Criteria => $c->whereLessThan('year', 2030), ['operator' => 'lt', 'field' => 'year', 'value' => 2030]],
            'whereLessThanOrEqual' => [static fn (Criteria $c): Criteria => $c->whereLessThanOrEqual('price', 9.5), ['operator' => 'lte', 'field' => 'price', 'value' => 9.5]],
            'whereContainsAny' => [static fn (Criteria $c): Criteria => $c->whereContainsAny('tags', ['a', 'b']), ['operator' => 'contains_any', 'field' => 'tags', 'value' => ['a', 'b']]],
            'whereContainsAll' => [static fn (Criteria $c): Criteria => $c->whereContainsAll('tags', ['a']), ['operator' => 'contains_all', 'field' => 'tags', 'value' => ['a']]],
            'whereAny' => [
                static fn (Criteria $c): Criteria => $c->whereAny(Filter::eq('owner', 'me'), Filter::eq('public', true)),
                ['operator' => 'or', 'conditions' => [
                    ['operator' => 'eq', 'field' => 'owner', 'value' => 'me'],
                    ['operator' => 'eq', 'field' => 'public', 'value' => true],
                ]],
            ],
        ];
    }

    /**
     * @param Closure(Criteria): Criteria $append
     * @param array<string, mixed> $expected
     */
    #[DataProvider('criteriaMethods')]
    public function test_each_criteria_method_appends_its_condition(Closure $append, array $expected): void
    {
        $filters = $append(Filter::where('tenant', 'acme'));

        $this->assertSame([
            'operator' => 'and',
            'conditions' => [['operator' => 'eq', 'field' => 'tenant', 'value' => 'acme'], $expected],
        ], $filters->toArray());
    }

    public function test_criteria_is_immutable(): void
    {
        $base = Filter::where('tenant', 'acme');
        $narrowed = $base->where('lang', 'en');

        $this->assertNotSame($base, $narrowed);
        $this->assertCount(1, $base->conditions());
        $this->assertCount(2, $narrowed->conditions());
    }

    public function test_criteria_from_and_group_continues_the_same_conjunction(): void
    {
        $criteria = Criteria::from(FilterGroup::allOf(Filter::eq('a', 1), Filter::eq('b', 2)))->where('c', 3);

        $this->assertSame(FilterCombinator::And, $criteria->operator());
        $this->assertCount(3, $criteria->conditions());
    }

    public function test_criteria_from_or_group_keeps_the_disjunction_nested(): void
    {
        $any = FilterGroup::anyOf(Filter::eq('a', 1), Filter::eq('b', 2));

        $criteria = Criteria::from($any)->where('c', 3);

        $this->assertSame([$any, $criteria->conditions()[1]], $criteria->conditions());
        $this->assertSame(FilterCombinator::And, $criteria->operator());
    }

    public function test_filters_accept_schema_fields_and_normalize_domain_values(): void
    {
        $status = DocumentField::string('status')->filterable();
        $publishedAt = DocumentField::integer('published_at')->filterable();

        $filters = Filter::where($status, FilterStatus::Published)
            ->whereGreaterThanOrEqual($publishedAt, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $this->assertSame([
            ['operator' => 'eq', 'field' => 'status', 'value' => 'published'],
            ['operator' => 'gte', 'field' => 'published_at', 'value' => 1767225600],
        ], $filters->toArray()['conditions']);
    }

    public function test_in_normalizes_every_value(): void
    {
        $filter = Filter::in('status', [FilterStatus::Published, new DateTimeImmutable('@86400'), 'draft', 3, 1.5, false]);

        $this->assertSame(['published', 86400, 'draft', 3, 1.5, false], $filter->value);
    }

    public function test_date_time_zone_does_not_change_the_normalized_timestamp(): void
    {
        $this->assertSame(
            Filter::gt('at', new DateTimeImmutable('2026-01-01T00:00:00+00:00'))->value,
            Filter::gt('at', new DateTimeImmutable('2026-01-01T01:00:00+01:00'))->value,
        );
    }

    public function test_schema_field_filters_are_validated_at_construction(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Filter operator gt requires a numeric field; "status" is string.');

        Filter::gt(DocumentField::string('status')->filterable(), 5);
    }

    public function test_non_filterable_schema_field_is_rejected_at_construction(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Document field "notes" is not filterable.');

        Filter::eq(DocumentField::string('notes'), 'x');
    }

    public function test_schema_field_value_type_is_enforced_at_construction(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Filter field "year" expects integer; string given.');

        Filter::eq(DocumentField::integer('year')->filterable(), '2026');
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

        $this->assertSame([
            'operator' => 'and',
            'conditions' => [
                ['operator' => 'eq', 'field' => 'tenant', 'value' => 'acme'],
                ['operator' => 'or', 'conditions' => [
                    ['operator' => 'eq', 'field' => 'published', 'value' => true],
                    ['operator' => 'eq', 'field' => 'owner', 'value' => 'user-1'],
                ]],
            ],
        ], $filters->toArray());
    }

    public function test_nested_groups_flatten_into_one_conjunction(): void
    {
        $scope = FilterGroup::and(Filter::eq('tenant', 'acme'));
        $user = FilterGroup::and(Filter::eq('lang', 'en'), Filter::gte('year', 2020));

        $merged = FilterGroup::and($scope, $user);

        $this->assertSame(FilterCombinator::And, $merged->operator());
        $this->assertSame([...$scope->conditions(), ...$user->conditions()], $merged->conditions());
    }

    public function test_nested_disjunctions_flatten_but_a_conjunction_keeps_its_boundary(): void
    {
        $inner = FilterGroup::allOf(Filter::eq('a', 1), Filter::eq('b', 2));

        $merged = FilterGroup::or(FilterGroup::anyOf(Filter::eq('c', 3), Filter::eq('d', 4)), $inner);

        $this->assertSame(FilterCombinator::Or, $merged->operator());
        $this->assertCount(3, $merged->conditions());
        $this->assertSame($inner, $merged->conditions()[2]);
    }

    public function test_scopes_always_merge_at_the_root_with_and(): void
    {
        $tenant = Filter::eq('tenant', 'acme');
        $visibility = FilterGroup::anyOf(Filter::eq('published', true), Filter::eq('owner', 'user-1'));

        $expression = FilterScope::merge($tenant, null, $visibility)?->expression();

        $this->assertInstanceOf(FilterGroup::class, $expression);
        $this->assertSame(FilterCombinator::And, $expression->operator());
        $this->assertSame([$tenant, $visibility], $expression->conditions());
    }

    public function test_a_disjunctive_scope_cannot_relax_another_scope(): void
    {
        $first = FilterGroup::anyOf(Filter::eq('tenant', 'acme'), Filter::eq('tenant', 'globex'));
        $second = FilterGroup::anyOf(Filter::eq('lang', 'en'), Filter::eq('lang', 'it'));

        $expression = FilterScope::merge($first, $second)?->expression();

        $this->assertInstanceOf(FilterGroup::class, $expression);
        $this->assertSame(FilterCombinator::And, $expression->operator());
        $this->assertSame([$first, $second], $expression->conditions());
    }

    public function test_single_scope_is_kept_as_is_and_no_scope_yields_null(): void
    {
        $only = Filter::eq('tenant', 'acme');

        $this->assertSame($only, FilterScope::merge(null, $only, null)?->expression());
        $this->assertNull(FilterScope::merge());
        $this->assertNull(FilterScope::merge(null, null));
    }

    public function test_group_merge_is_null_tolerant_and_always_returns_a_conjunction(): void
    {
        $this->assertNull(FilterGroup::merge(null, null));

        $single = FilterGroup::merge(null, Filter::eq('tenant', 'acme'));
        $this->assertInstanceOf(FilterGroup::class, $single);
        $this->assertSame(FilterCombinator::And, $single->operator());
        $this->assertCount(1, $single->conditions());

        $any = FilterGroup::anyOf(Filter::eq('a', 1), Filter::eq('b', 2));
        $wrapped = FilterGroup::merge($any);
        $this->assertInstanceOf(FilterGroup::class, $wrapped);
        $this->assertSame(FilterCombinator::And, $wrapped->operator());
        $this->assertSame([$any], $wrapped->conditions());
    }

    public function test_conditions_carry_field_operator_and_value(): void
    {
        $filter = Filter::eq('sourceType', 'file');

        $this->assertSame('sourceType', $filter->field);
        $this->assertSame(FilterOperator::Eq, $filter->operator);
        $this->assertSame('file', $filter->value);
    }

    /**
     * @return array<string, array{Closure(): FilterExpression, class-string<Throwable>, string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'empty field' => [static fn (): Filter => Filter::eq('', 'value'), VectorStoreException::class, 'Filter field name cannot be empty.'],
            'empty in' => [static fn (): Filter => Filter::in('sourceType', []), VectorStoreException::class, 'Filter "in" on field "sourceType" requires at least one value.'],
            'null in value' => [
                /** @phpstan-ignore-next-line deliberately wrong type */
                static fn (): Filter => Filter::in('sourceType', ['file', null]),
                VectorStoreException::class,
                'Filter "in" on field "sourceType" accepts scalar values only.',
            ],
            'nested array in value' => [
                /** @phpstan-ignore-next-line deliberately wrong type */
                static fn (): Filter => Filter::in('tenant', [['$ne' => null]]),
                VectorStoreException::class,
                'Filter "in" on field "tenant" accepts scalar values only.',
            ],
            'string backed enum range' => [static fn (): Filter => Filter::gt('status', FilterStatus::Published), VectorStoreException::class, 'Filter "gt" on field "status" requires a numeric value.'],
            'empty contains any' => [static fn (): Filter => Filter::containsAny('tags', []), VectorStoreException::class, 'Filter "contains_any" on field "tags" requires at least one value.'],
            'empty contains all' => [static fn (): Filter => Filter::containsAll('tags', []), VectorStoreException::class, 'Filter "contains_all" on field "tags" requires at least one value.'],
            'non string contains' => [
                /** @phpstan-ignore-next-line deliberately wrong type */
                static fn (): Filter => Filter::containsAll('tags', ['php', 3]),
                VectorStoreException::class,
                'Filter "contains_all" on field "tags" accepts string values only.',
            ],
            'empty and group' => [static fn (): FilterGroup => FilterGroup::and(), VectorStoreException::class, 'A filter group requires at least one condition.'],
            'empty or group' => [static fn (): FilterGroup => FilterGroup::or(), VectorStoreException::class, 'A filter group requires at least one condition.'],
            'empty where any' => [static fn (): Criteria => Filter::where('a', 1)->whereAny(), VectorStoreException::class, 'A filter group requires at least one condition.'],
            'null value' => [
                /** @phpstan-ignore-next-line deliberately wrong type */
                static fn (): Filter => Filter::eq('field', null),
                TypeError::class,
                'must be of type BackedEnum|DateTimeInterface|string|int|float|bool, null given',
            ],
            'string range' => [
                /** @phpstan-ignore-next-line deliberately wrong type */
                static fn (): Filter => Filter::gte('published_at', '2026-01-01'),
                TypeError::class,
                'must be of type BackedEnum|DateTimeInterface|int|float, string given',
            ],
        ];
    }

    /**
     * @param Closure(): FilterExpression $build
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(Closure $build, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        $build();
    }

    public function test_raw_is_tagged_with_its_target_store(): void
    {
        $raw = Filter::raw(MeilisearchVectorStore::class, "sourceType = 'file'");

        $this->assertInstanceOf(RawFilter::class, $raw);
        $this->assertSame(MeilisearchVectorStore::class, $raw->store);
        $this->assertSame("sourceType = 'file'", $raw->fragment);
        $this->assertSame([
            'operator' => 'raw',
            'store' => MeilisearchVectorStore::class,
            'fragment' => "sourceType = 'file'",
        ], $raw->toArray());
    }
}
