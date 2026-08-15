<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Compilers\ChromaFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Compilers\ElasticsearchFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Compilers\MariaDBFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Compilers\MeilisearchFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Compilers\MongoDBFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Compilers\OpenSearchFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Compilers\PineconeFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Compilers\QdrantFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Compilers\TypesenseFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Compilers\WeaviateFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\OpenSearchVectorStore;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use PHPUnit\Framework\TestCase;

class FilterCompilersTest extends TestCase
{
    public function test_qdrant_compiles_must_conditions(): void
    {
        $must = (new QdrantFilterCompiler())->compile(FilterGroup::and(
            Filter::eq('sourceType', 'file'),
            Filter::neq('lang', 'de'),
            Filter::in('year', [2023, 2024]),
            Filter::gte('score', 0.5),
        ));

        $this->assertSame([
            ['key' => 'sourceType', 'match' => ['value' => 'file']],
            ['key' => 'lang', 'match' => ['except' => ['de']]],
            ['key' => 'year', 'match' => ['any' => [2023, 2024]]],
            ['key' => 'score', 'range' => ['gte' => 0.5]],
        ], $must);
    }

    public function test_qdrant_passes_its_own_raw_fragment_through(): void
    {
        $must = (new QdrantFilterCompiler())->compile(FilterGroup::and(
            Filter::raw(QdrantVectorStore::class, ['key' => 'city', 'geo_radius' => ['center' => [1, 2]]]),
        ));

        $this->assertSame([['key' => 'city', 'geo_radius' => ['center' => [1, 2]]]], $must);
    }

    public function test_raw_fragment_for_another_store_is_refused(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessageMatches('/targets .*ElasticsearchVectorStore/');

        (new QdrantFilterCompiler())->compile(FilterGroup::and(
            Filter::raw(ElasticsearchVectorStore::class, ['term' => ['lang' => 'en']]),
        ));
    }

    public function test_meilisearch_compiles_an_expression_with_escaping(): void
    {
        $expression = (new MeilisearchFilterCompiler())->compile(FilterGroup::and(
            Filter::eq('sourceName', "o'reilly.pdf"),
            Filter::in('lang', ['en', 'it']),
            Filter::lt('year', 2026),
            Filter::eq('reviewed', true),
        ));

        $this->assertSame(
            "sourceName = 'o\\'reilly.pdf' AND lang IN ['en', 'it'] AND year < 2026 AND reviewed = true",
            $expression
        );
    }

    public function test_pinecone_wraps_multiple_conditions_in_and(): void
    {
        $compiler = new PineconeFilterCompiler();

        $this->assertSame(
            ['sourceType' => ['$eq' => 'file']],
            $compiler->compile(FilterGroup::and(Filter::eq('sourceType', 'file')))
        );

        $this->assertSame(
            ['$and' => [
                ['sourceType' => ['$eq' => 'file']],
                ['year' => ['$gt' => 2020]],
            ]],
            $compiler->compile(FilterGroup::and(
                Filter::eq('sourceType', 'file'),
                Filter::gt('year', 2020),
            ))
        );
    }

    public function test_chroma_uses_the_same_operator_family(): void
    {
        $this->assertSame(
            ['lang' => ['$in' => ['en', 'it']]],
            (new ChromaFilterCompiler())->compile(FilterGroup::and(Filter::in('lang', ['en', 'it'])))
        );
    }

    public function test_elasticsearch_compiles_a_bool_query(): void
    {
        $query = (new ElasticsearchFilterCompiler())->compile(FilterGroup::and(
            Filter::eq('sourceType', 'file'),
            Filter::neq('lang', 'de'),
            Filter::gte('year', 2020),
        ));

        $this->assertSame([
            'bool' => [
                'must' => [
                    ['term' => ['sourceType' => 'file']],
                    ['range' => ['year' => ['gte' => 2020]]],
                ],
                'must_not' => [
                    ['term' => ['lang' => 'de']],
                ],
            ],
        ], $query);
    }

    public function test_opensearch_refuses_elasticsearch_raw_fragments(): void
    {
        $this->expectException(VectorStoreException::class);

        (new OpenSearchFilterCompiler())->compile(FilterGroup::and(
            Filter::raw(ElasticsearchVectorStore::class, ['term' => ['lang' => 'en']]),
        ));
    }

    public function test_opensearch_accepts_its_own_raw_fragments(): void
    {
        $query = (new OpenSearchFilterCompiler())->compile(FilterGroup::and(
            Filter::raw(OpenSearchVectorStore::class, ['term' => ['lang' => 'en']]),
        ));

        $this->assertSame(['bool' => ['must' => [['term' => ['lang' => 'en']]]]], $query);
    }

    public function test_typesense_compiles_a_filter_by_expression(): void
    {
        $expression = (new TypesenseFilterCompiler())->compile(FilterGroup::and(
            Filter::eq('sourceType', 'file'),
            Filter::in('lang', ['en', 'it']),
            Filter::gt('year', 2020),
        ));

        $this->assertSame('sourceType:=`file` && lang:=[`en`, `it`] && year:>2020', $expression);
    }

    public function test_mongodb_nests_custom_metadata_fields(): void
    {
        $filter = (new MongoDBFilterCompiler())->compile(FilterGroup::and(
            Filter::eq('sourceType', 'file'),
            Filter::eq('tenant', 'acme'),
        ));

        $this->assertSame([
            '$and' => [
                ['sourceType' => ['$eq' => 'file']],
                ['metadata.tenant' => ['$eq' => 'acme']],
            ],
        ], $filter);
    }

    public function test_mariadb_compiles_sql_with_named_bindings(): void
    {
        $compiled = (new MariaDBFilterCompiler())->compile(FilterGroup::and(
            Filter::eq('sourceType', 'file'),
            Filter::in('lang', ['en', 'it']),
            Filter::gte('year', 2020),
            Filter::eq('reviewed', true),
        ));

        $this->assertSame(
            "sourceType = :f0 AND JSON_VALUE(metadata, '$.lang') IN (:f1, :f2) " .
            "AND JSON_VALUE(metadata, '$.year') >= :f3 AND JSON_VALUE(metadata, '$.reviewed') = :f4",
            $compiled['sql']
        );
        $this->assertSame(
            [':f0' => 'file', ':f1' => 'en', ':f2' => 'it', ':f3' => 2020, ':f4' => 1],
            $compiled['bindings']
        );
    }

    public function test_mariadb_refuses_unsafe_field_names(): void
    {
        $this->expectException(VectorStoreException::class);

        (new MariaDBFilterCompiler())->compile(FilterGroup::and(
            Filter::eq("x') OR ('1'='1", 'boom'),
        ));
    }

    public function test_weaviate_compiles_rest_and_graphql(): void
    {
        $compiler = new WeaviateFilterCompiler();
        $filters = FilterGroup::and(
            Filter::eq('sourceType', 'file'),
            Filter::neq('sourceName', 'skip.txt'),
        );

        $this->assertSame([
            'operator' => 'And',
            'operands' => [
                ['path' => ['sourceType'], 'operator' => 'Equal', 'valueText' => 'file'],
                ['path' => ['sourceName'], 'operator' => 'NotEqual', 'valueText' => 'skip.txt'],
            ],
        ], $compiler->compile($filters));

        $this->assertSame(
            '{operator: And, operands: [' .
            '{path: ["sourceType"], operator: Equal, valueText: "file"}, ' .
            '{path: ["sourceName"], operator: NotEqual, valueText: "skip.txt"}]}',
            $compiler->compileGraphQL($filters)
        );
    }

    public function test_weaviate_refuses_custom_metadata_fields(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessageMatches('/not filterable/');

        (new WeaviateFilterCompiler())->compile(FilterGroup::and(Filter::eq('tenant', 'acme')));
    }
}
