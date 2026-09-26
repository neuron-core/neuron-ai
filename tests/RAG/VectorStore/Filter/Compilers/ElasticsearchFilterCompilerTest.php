<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Filter\Compilers;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\Compilers\ElasticsearchFilterCompiler;
use NeuronAI\RAG\VectorStore\Compilers\OpenSearchFilterCompiler;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\OpenSearchVectorStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OpenSearch shares the Elasticsearch query DSL, so both compilers must
 * produce identical output for portable filters.
 */
class ElasticsearchFilterCompilerTest extends TestCase
{
    /**
     * @return array<string, array{FilterExpression, array<string, mixed>}>
     */
    public static function expressions(): array
    {
        $neq = ['bool' => [
            'must' => [['exists' => ['field' => 'lang']]],
            'must_not' => [['term' => ['lang' => 'de']]],
        ]];

        return [
            'eq' => [Filter::eq('lang', 'en'), ['term' => ['lang' => 'en']]],
            'eq boolean' => [Filter::eq('draft', false), ['term' => ['draft' => false]]],
            'neq requires the field to exist' => [Filter::neq('lang', 'de'), $neq],
            'in' => [Filter::in('year', [2023, 2024]), ['terms' => ['year' => [2023, 2024]]]],
            'gt' => [Filter::gt('year', 2020), ['range' => ['year' => ['gt' => 2020]]]],
            'gte' => [Filter::gte('year', 2020), ['range' => ['year' => ['gte' => 2020]]]],
            'lt' => [Filter::lt('price', 9.5), ['range' => ['price' => ['lt' => 9.5]]]],
            'lte' => [Filter::lte('price', -1), ['range' => ['price' => ['lte' => -1]]]],
            'contains any' => [Filter::containsAny('tags', ['a', 'b']), ['terms' => ['tags' => ['a', 'b']]]],
            'contains all' => [
                Filter::containsAll('tags', ['a', 'b']),
                ['bool' => ['must' => [['term' => ['tags' => 'a']], ['term' => ['tags' => 'b']]]]],
            ],
            'conjunction without neq has no must_not' => [
                FilterGroup::allOf(Filter::eq('a', 1), Filter::eq('b', 2)),
                ['bool' => ['must' => [['term' => ['a' => 1]], ['term' => ['b' => 2]]]]],
            ],
            'conjunction of only neq' => [
                FilterGroup::allOf(Filter::neq('lang', 'de')),
                $neq,
            ],
            'disjunction needs one match and keeps neq self-contained' => [
                FilterGroup::anyOf(Filter::eq('a', 1), Filter::neq('lang', 'de')),
                ['bool' => ['should' => [['term' => ['a' => 1]], $neq], 'minimum_should_match' => 1]],
            ],
            'conjunction nests a disjunction' => [
                FilterGroup::allOf(Filter::eq('tenant', 'acme'), FilterGroup::anyOf(Filter::eq('a', 1), Filter::eq('b', 2))),
                ['bool' => ['must' => [
                    ['term' => ['tenant' => 'acme']],
                    ['bool' => ['should' => [['term' => ['a' => 1]], ['term' => ['b' => 2]]], 'minimum_should_match' => 1]],
                ]]],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('expressions')]
    public function test_compiles_portable_filters_identically_for_both_engines(FilterExpression $filters, array $expected): void
    {
        $this->assertSame($expected, (new ElasticsearchFilterCompiler())->compile($filters));
        $this->assertSame($expected, (new OpenSearchFilterCompiler())->compile($filters));
    }

    public function test_query_dsl_lookalike_values_stay_term_values(): void
    {
        $this->assertSame(
            ['term' => ['tenant' => '{"match_all":{}}']],
            (new ElasticsearchFilterCompiler())->compile(Filter::eq('tenant', '{"match_all":{}}')),
        );
    }

    public function test_passes_its_own_raw_fragment_through(): void
    {
        $fragment = ['geo_distance' => ['distance' => '2km', 'location' => [9.1, 45.4]]];

        $this->assertSame($fragment, (new ElasticsearchFilterCompiler())->compile(Filter::raw(ElasticsearchVectorStore::class, $fragment)));
        $this->assertSame(
            ['bool' => ['should' => [['term' => ['a' => 1]], $fragment], 'minimum_should_match' => 1]],
            (new OpenSearchFilterCompiler())->compile(FilterGroup::anyOf(Filter::eq('a', 1), Filter::raw(OpenSearchVectorStore::class, $fragment))),
        );
    }

    public function test_elasticsearch_refuses_an_opensearch_raw_fragment(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage(
            'Raw filter targets ' . OpenSearchVectorStore::class . '; it cannot be compiled for ' . ElasticsearchVectorStore::class . '.'
        );

        (new ElasticsearchFilterCompiler())->compile(FilterGroup::allOf(
            Filter::eq('a', 1),
            FilterGroup::anyOf(Filter::eq('b', 2), Filter::raw(OpenSearchVectorStore::class, ['match_all' => []])),
        ));
    }
}
