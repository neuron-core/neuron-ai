<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Filter\Compilers;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\Compilers\QdrantFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\WeaviateVectorStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QdrantFilterCompilerTest extends TestCase
{
    /**
     * @return array<string, array{FilterExpression, array<int, array<string, mixed>>}>
     */
    public static function expressions(): array
    {
        return [
            'eq' => [Filter::eq('lang', 'en'), [['key' => 'lang', 'match' => ['value' => 'en']]]],
            'eq boolean' => [Filter::eq('draft', false), [['key' => 'draft', 'match' => ['value' => false]]]],
            'neq' => [Filter::neq('lang', 'de'), [['key' => 'lang', 'match' => ['except' => ['de']]]]],
            'in' => [Filter::in('year', [1, 2]), [['key' => 'year', 'match' => ['any' => [1, 2]]]]],
            'gt' => [Filter::gt('year', 1), [['key' => 'year', 'range' => ['gt' => 1]]]],
            'gte' => [Filter::gte('year', 1), [['key' => 'year', 'range' => ['gte' => 1]]]],
            'lt' => [Filter::lt('price', 1.5), [['key' => 'price', 'range' => ['lt' => 1.5]]]],
            'lte' => [Filter::lte('price', -1), [['key' => 'price', 'range' => ['lte' => -1]]]],
            'contains any' => [Filter::containsAny('tags', ['a', 'b']), [['key' => 'tags', 'match' => ['any' => ['a', 'b']]]]],
            'contains all' => [
                Filter::containsAll('tags', ['a', 'b']),
                [['must' => [['key' => 'tags', 'match' => ['value' => 'a']], ['key' => 'tags', 'match' => ['value' => 'b']]]]],
            ],
            'conjunctions flatten into must conditions' => [
                FilterGroup::allOf(Filter::eq('a', 1), FilterGroup::allOf(Filter::eq('b', 2))),
                [['key' => 'a', 'match' => ['value' => 1]], ['key' => 'b', 'match' => ['value' => 2]]],
            ],
            'disjunction becomes a nested should filter' => [
                FilterGroup::anyOf(Filter::eq('a', 1), Filter::eq('b', 2)),
                [['should' => [['key' => 'a', 'match' => ['value' => 1]], ['key' => 'b', 'match' => ['value' => 2]]]]],
            ],
            'conjunction inside a disjunction keeps its must boundary' => [
                FilterGroup::anyOf(Filter::eq('a', 1), FilterGroup::allOf(Filter::eq('b', 2), Filter::eq('c', 3))),
                [['should' => [
                    ['key' => 'a', 'match' => ['value' => 1]],
                    ['must' => [['key' => 'b', 'match' => ['value' => 2]], ['key' => 'c', 'match' => ['value' => 3]]]],
                ]]],
            ],
            'disjunction inside a conjunction is one must condition' => [
                FilterGroup::allOf(Filter::eq('tenant', 'acme'), FilterGroup::anyOf(Filter::eq('a', 1), Filter::eq('b', 2))),
                [
                    ['key' => 'tenant', 'match' => ['value' => 'acme']],
                    ['should' => [['key' => 'a', 'match' => ['value' => 1]], ['key' => 'b', 'match' => ['value' => 2]]]],
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $expected
     */
    #[DataProvider('expressions')]
    public function test_compiles_must_conditions(FilterExpression $filters, array $expected): void
    {
        $this->assertSame($expected, (new QdrantFilterCompiler())->compile($filters));
    }

    public function test_raw_fragment_inside_a_disjunction_is_passed_through(): void
    {
        $fragment = ['key' => 'city', 'geo_radius' => ['center' => ['lon' => 9.1, 'lat' => 45.4], 'radius' => 1000]];

        $this->assertSame(
            [['should' => [['key' => 'a', 'match' => ['value' => 1]], $fragment]]],
            (new QdrantFilterCompiler())->compile(FilterGroup::anyOf(Filter::eq('a', 1), Filter::raw(QdrantVectorStore::class, $fragment))),
        );
    }

    public function test_raw_fragment_for_another_store_is_refused_even_when_nested(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage(
            'Raw filter targets ' . WeaviateVectorStore::class . '; it cannot be compiled for ' . QdrantVectorStore::class . '.'
        );

        (new QdrantFilterCompiler())->compile(FilterGroup::allOf(
            Filter::eq('a', 1),
            FilterGroup::anyOf(Filter::eq('b', 2), Filter::raw(WeaviateVectorStore::class, [])),
        ));
    }
}
