<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Filter\Compilers;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\Compilers\WeaviateFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\WeaviateVectorStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WeaviateFilterCompilerTest extends TestCase
{
    /**
     * @return array<string, array{Filter, array<string, mixed>, string}>
     */
    public static function conditions(): array
    {
        return [
            'eq text' => [
                Filter::eq('lang', 'en'),
                ['path' => ['lang'], 'operator' => 'Equal', 'valueText' => 'en'],
                '{path: ["lang"], operator: Equal, valueText: "en"}',
            ],
            'neq text' => [
                Filter::neq('lang', 'de'),
                ['path' => ['lang'], 'operator' => 'NotEqual', 'valueText' => 'de'],
                '{path: ["lang"], operator: NotEqual, valueText: "de"}',
            ],
            'eq boolean' => [
                Filter::eq('draft', false),
                ['path' => ['draft'], 'operator' => 'Equal', 'valueBoolean' => false],
                '{path: ["draft"], operator: Equal, valueBoolean: false}',
            ],
            'gt int' => [
                Filter::gt('year', 2020),
                ['path' => ['year'], 'operator' => 'GreaterThan', 'valueInt' => 2020],
                '{path: ["year"], operator: GreaterThan, valueInt: 2020}',
            ],
            'gte int' => [
                Filter::gte('year', -1),
                ['path' => ['year'], 'operator' => 'GreaterThanEqual', 'valueInt' => -1],
                '{path: ["year"], operator: GreaterThanEqual, valueInt: -1}',
            ],
            'lt number' => [
                Filter::lt('price', 9.5),
                ['path' => ['price'], 'operator' => 'LessThan', 'valueNumber' => 9.5],
                '{path: ["price"], operator: LessThan, valueNumber: 9.5}',
            ],
            'lte number' => [
                Filter::lte('price', 0.25),
                ['path' => ['price'], 'operator' => 'LessThanEqual', 'valueNumber' => 0.25],
                '{path: ["price"], operator: LessThanEqual, valueNumber: 0.25}',
            ],
            'in text' => [
                Filter::in('lang', ['en', 'it']),
                ['path' => ['lang'], 'operator' => 'ContainsAny', 'valueTextArray' => ['en', 'it']],
                '{path: ["lang"], operator: ContainsAny, valueTextArray: ["en", "it"]}',
            ],
            'in int' => [
                Filter::in('year', [1, 2]),
                ['path' => ['year'], 'operator' => 'ContainsAny', 'valueIntArray' => [1, 2]],
                '{path: ["year"], operator: ContainsAny, valueIntArray: [1, 2]}',
            ],
            'in number' => [
                Filter::in('price', [1.5, 2.5]),
                ['path' => ['price'], 'operator' => 'ContainsAny', 'valueNumberArray' => [1.5, 2.5]],
                '{path: ["price"], operator: ContainsAny, valueNumberArray: [1.5, 2.5]}',
            ],
            'in boolean' => [
                Filter::in('draft', [true]),
                ['path' => ['draft'], 'operator' => 'ContainsAny', 'valueBooleanArray' => [true]],
                '{path: ["draft"], operator: ContainsAny, valueBooleanArray: [true]}',
            ],
            'contains any' => [
                Filter::containsAny('tags', ['a', 'b']),
                ['path' => ['tags'], 'operator' => 'ContainsAny', 'valueTextArray' => ['a', 'b']],
                '{path: ["tags"], operator: ContainsAny, valueTextArray: ["a", "b"]}',
            ],
            'contains all' => [
                Filter::containsAll('tags', ['a']),
                ['path' => ['tags'], 'operator' => 'ContainsAll', 'valueTextArray' => ['a']],
                '{path: ["tags"], operator: ContainsAll, valueTextArray: ["a"]}',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $rest
     */
    #[DataProvider('conditions')]
    public function test_compiles_every_operator_to_rest_and_graphql(Filter $filter, array $rest, string $graphql): void
    {
        $compiler = new WeaviateFilterCompiler();

        $this->assertSame($rest, $compiler->compile($filter));
        $this->assertSame($graphql, $compiler->compileGraphQL($filter));
    }

    public function test_nested_groups_render_with_operands(): void
    {
        $filters = FilterGroup::anyOf(
            Filter::eq('a', 'x'),
            FilterGroup::allOf(Filter::eq('b', 1), Filter::eq('c', true)),
        );

        $this->assertSame(
            '{operator: Or, operands: [{path: ["a"], operator: Equal, valueText: "x"}, ' .
            '{operator: And, operands: [{path: ["b"], operator: Equal, valueInt: 1}, {path: ["c"], operator: Equal, valueBoolean: true}]}]}',
            (new WeaviateFilterCompiler())->compileGraphQL($filters),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function hostileStrings(): array
    {
        return [
            'quote breakout' => ['x"}) { __schema { types { name } } } #', '"x\"}) { __schema { types { name } } } #"'],
            'backslash' => ['a\\', '"a\\\\"'],
            'newline' => ["a\nb", '"a\nb"'],
            'operator keyword' => ['Or', '"Or"'],
            'multibyte' => ['Città', '"Citt\\u00e0"'],
        ];
    }

    #[DataProvider('hostileStrings')]
    public function test_graphql_string_values_are_json_escaped(string $value, string $literal): void
    {
        $this->assertSame(
            "{path: [\"tenant\"], operator: Equal, valueText: {$literal}}",
            (new WeaviateFilterCompiler())->compileGraphQL(Filter::eq('tenant', $value)),
        );
    }

    public function test_field_names_render_as_quoted_path_strings(): void
    {
        $this->assertSame(
            '{path: ["operator"], operator: Equal, valueText: "Or"}',
            (new WeaviateFilterCompiler())->compileGraphQL(Filter::eq('operator', 'Or')),
        );
    }

    public function test_passes_its_own_raw_fragment_through_both_formats(): void
    {
        $fragment = ['path' => ['location'], 'operator' => 'WithinGeoRange', 'valueGeoRange' => ['distance' => ['max' => 2000]]];
        $raw = Filter::raw(WeaviateVectorStore::class, $fragment);
        $compiler = new WeaviateFilterCompiler();

        $this->assertSame($fragment, $compiler->compile($raw));
        $this->assertSame(
            '{path: ["location"], operator: WithinGeoRange, valueGeoRange: {distance: {max: 2000}}}',
            $compiler->compileGraphQL($raw),
        );
    }

    public function test_refuses_a_raw_fragment_for_another_store(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage(
            'Raw filter targets ' . QdrantVectorStore::class . '; it cannot be compiled for ' . WeaviateVectorStore::class . '.'
        );

        (new WeaviateFilterCompiler())->compileGraphQL(FilterGroup::anyOf(
            Filter::eq('a', 1),
            Filter::raw(QdrantVectorStore::class, ['key' => 'a']),
        ));
    }
}
