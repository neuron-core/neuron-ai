<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Filter\Compilers;

use NeuronAI\Exceptions\VectorStoreException;
use Closure;
use NeuronAI\RAG\VectorStore\ChromaVectorStore;
use NeuronAI\RAG\VectorStore\Compilers\ChromaFilterCompiler;
use NeuronAI\RAG\VectorStore\Compilers\ElasticsearchFilterCompiler;
use NeuronAI\RAG\VectorStore\Compilers\MariaDBFilterCompiler;
use NeuronAI\RAG\VectorStore\Compilers\MeilisearchFilterCompiler;
use NeuronAI\RAG\VectorStore\Compilers\MongoDBFilterCompiler;
use NeuronAI\RAG\VectorStore\Compilers\OpenSearchFilterCompiler;
use NeuronAI\RAG\VectorStore\Compilers\PineconeFilterCompiler;
use NeuronAI\RAG\VectorStore\Compilers\QdrantFilterCompiler;
use NeuronAI\RAG\VectorStore\Compilers\TypesenseFilterCompiler;
use NeuronAI\RAG\VectorStore\Compilers\WeaviateFilterCompiler;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\RAG\VectorStore\MongoDBVectorStore;
use NeuronAI\RAG\VectorStore\OpenSearchVectorStore;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\TypesenseVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\RAG\VectorStore\WeaviateVectorStore;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->expectExceptionMessage(
            'Raw filter targets ' . ElasticsearchVectorStore::class . '; it cannot be compiled for ' . QdrantVectorStore::class . '.'
        );

        (new QdrantFilterCompiler())->compile(FilterGroup::and(
            Filter::raw(ElasticsearchVectorStore::class, ['term' => ['lang' => 'en']]),
        ));
    }

    /**
     * @return array<string, array{Closure(FilterExpression): mixed, class-string}>
     */
    public static function compilers(): array
    {
        return [
            'chroma' => [static fn (FilterExpression $filters): mixed => (new ChromaFilterCompiler())->compile($filters), ChromaVectorStore::class],
            'elasticsearch' => [static fn (FilterExpression $filters): mixed => (new ElasticsearchFilterCompiler())->compile($filters), ElasticsearchVectorStore::class],
            'mariadb' => [static fn (FilterExpression $filters): mixed => (new MariaDBFilterCompiler())->compile($filters), MariaDBVectorStore::class],
            'meilisearch' => [static fn (FilterExpression $filters): mixed => (new MeilisearchFilterCompiler())->compile($filters), MeilisearchVectorStore::class],
            'mongodb' => [static fn (FilterExpression $filters): mixed => (new MongoDBFilterCompiler())->compile($filters), MongoDBVectorStore::class],
            'opensearch' => [static fn (FilterExpression $filters): mixed => (new OpenSearchFilterCompiler())->compile($filters), OpenSearchVectorStore::class],
            'pinecone' => [static fn (FilterExpression $filters): mixed => (new PineconeFilterCompiler())->compile($filters), PineconeVectorStore::class],
            'qdrant' => [static fn (FilterExpression $filters): mixed => (new QdrantFilterCompiler())->compile($filters), QdrantVectorStore::class],
            'typesense' => [static fn (FilterExpression $filters): mixed => (new TypesenseFilterCompiler())->compile($filters), TypesenseVectorStore::class],
            'weaviate' => [static fn (FilterExpression $filters): mixed => (new WeaviateFilterCompiler())->compile($filters), WeaviateVectorStore::class],
        ];
    }

    /**
     * @param Closure(FilterExpression): mixed $compile
     * @param class-string $store
     */
    #[DataProvider('compilers')]
    public function test_raw_fragment_tagged_with_the_shared_interface_is_refused_by_every_store(Closure $compile, string $store): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage(
            'Raw filter targets ' . VectorStoreInterface::class . '; it cannot be compiled for ' . $store . '.'
        );

        $compile(Filter::raw(VectorStoreInterface::class, "sourceType = 'file'"));
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
                    ['exists' => ['field' => 'lang']],
                    ['range' => ['year' => ['gte' => 2020]]],
                ],
                'must_not' => [
                    ['term' => ['lang' => 'de']],
                ],
            ],
        ], $query);
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

    public function test_weaviate_compiles_custom_metadata_fields(): void
    {
        $this->assertSame(
            ['path' => ['tenant'], 'operator' => 'Equal', 'valueText' => 'acme'],
            (new WeaviateFilterCompiler())->compile(FilterGroup::and(Filter::eq('tenant', 'acme'))),
        );
    }

    public function test_logical_groups_compile_recursively(): void
    {
        $filters = FilterGroup::allOf(
            Filter::eq('tenant', 'acme'),
            FilterGroup::anyOf(Filter::eq('lang', 'en'), Filter::eq('lang', 'it')),
        );

        $this->assertSame([
            '$and' => [
                ['tenant' => ['$eq' => 'acme']],
                ['$or' => [
                    ['lang' => ['$eq' => 'en']],
                    ['lang' => ['$eq' => 'it']],
                ]],
            ],
        ], (new PineconeFilterCompiler())->compile($filters));

        $this->assertSame(
            "tenant = 'acme' AND (lang = 'en' OR lang = 'it')",
            (new MeilisearchFilterCompiler())->compile($filters),
        );

        $this->assertSame(
            'tenant:=`acme` && (lang:=`en` || lang:=`it`)',
            (new TypesenseFilterCompiler())->compile($filters),
        );
    }

    public function test_string_array_containment_compiles_portably(): void
    {
        $filters = FilterGroup::allOf(
            Filter::containsAny('tags', ['php', 'rag']),
            Filter::containsAll('roles', ['reader', 'editor']),
        );

        $this->assertSame([
            '$and' => [
                ['tags' => ['$in' => ['php', 'rag']]],
                ['$and' => [
                    ['roles' => ['$eq' => 'reader']],
                    ['roles' => ['$eq' => 'editor']],
                ]],
            ],
        ], (new PineconeFilterCompiler())->compile($filters));

        $this->assertSame(
            "tags IN ['php', 'rag'] AND (roles = 'reader' AND roles = 'editor')",
            (new MeilisearchFilterCompiler())->compile($filters),
        );

        $this->assertSame([
            'operator' => 'And',
            'operands' => [
                [
                    'path' => ['tags'],
                    'operator' => 'ContainsAny',
                    'valueTextArray' => ['php', 'rag'],
                ],
                [
                    'path' => ['roles'],
                    'operator' => 'ContainsAll',
                    'valueTextArray' => ['reader', 'editor'],
                ],
            ],
        ], (new WeaviateFilterCompiler())->compile($filters));
    }
}
