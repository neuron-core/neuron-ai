<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Filter\Compilers;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\ChromaVectorStore;
use NeuronAI\RAG\VectorStore\Compilers\ChromaFilterCompiler;
use NeuronAI\RAG\VectorStore\Compilers\MongoDBFilterCompiler;
use NeuronAI\RAG\VectorStore\Compilers\PineconeFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MongoDBVectorStore;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use LogicException;

/**
 * Pinecone, ChromaDB and MongoDB share the {field: {$op: value}} dialect;
 * each differs only where its backend does.
 */
class MongoStyleFilterCompilersTest extends TestCase
{
    /**
     * @return array<string, array{Filter, array<string, mixed>}>
     */
    public static function pineconeOperators(): array
    {
        return [
            'eq' => [Filter::eq('lang', 'en'), ['lang' => ['$eq' => 'en']]],
            'eq boolean' => [Filter::eq('draft', true), ['draft' => ['$eq' => true]]],
            'neq' => [Filter::neq('lang', 'de'), ['lang' => ['$ne' => 'de']]],
            'in' => [Filter::in('year', [2023, 2024]), ['year' => ['$in' => [2023, 2024]]]],
            'gt' => [Filter::gt('year', 2020), ['year' => ['$gt' => 2020]]],
            'gte' => [Filter::gte('year', 2020), ['year' => ['$gte' => 2020]]],
            'lt' => [Filter::lt('price', 9.5), ['price' => ['$lt' => 9.5]]],
            'lte' => [Filter::lte('price', 0), ['price' => ['$lte' => 0]]],
            'contains any' => [Filter::containsAny('tags', ['a', 'b']), ['tags' => ['$in' => ['a', 'b']]]],
            'contains all' => [Filter::containsAll('tags', ['a', 'b']), ['$and' => [['tags' => ['$eq' => 'a']], ['tags' => ['$eq' => 'b']]]]],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('pineconeOperators')]
    public function test_pinecone_compiles_every_operator(Filter $filter, array $expected): void
    {
        $this->assertSame($expected, (new PineconeFilterCompiler())->compile($filter));
    }

    /**
     * @return array<string, array{Filter, array<string, mixed>}>
     */
    public static function chromaOperators(): array
    {
        return [
            'eq' => [Filter::eq('lang', 'en'), ['lang' => ['$eq' => 'en']]],
            'neq' => [Filter::neq('lang', 'de'), ['lang' => ['$ne' => 'de']]],
            'in' => [Filter::in('lang', ['en', 'it']), ['lang' => ['$in' => ['en', 'it']]]],
            'lte' => [Filter::lte('year', 2020), ['year' => ['$lte' => 2020]]],
            'contains any' => [Filter::containsAny('tags', ['a', 'b']), ['$or' => [['tags' => ['$contains' => 'a']], ['tags' => ['$contains' => 'b']]]]],
            'contains all' => [Filter::containsAll('tags', ['a', 'b']), ['$and' => [['tags' => ['$contains' => 'a']], ['tags' => ['$contains' => 'b']]]]],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('chromaOperators')]
    public function test_chroma_compiles_array_containment_with_contains(Filter $filter, array $expected): void
    {
        $this->assertSame($expected, (new ChromaFilterCompiler())->compile($filter));
    }

    /**
     * @return array<string, array{Filter, array<string, mixed>}>
     */
    public static function mongoOperators(): array
    {
        return [
            'framework field stays top level' => [Filter::eq('sourceType', 'file'), ['sourceType' => ['$eq' => 'file']]],
            'metadata eq' => [Filter::eq('tenant', 'acme'), ['metadata.tenant' => ['$eq' => 'acme']]],
            'metadata neq requires the field to exist' => [Filter::neq('tenant', 'acme'), ['metadata.tenant' => ['$ne' => 'acme', '$exists' => true]]],
            'framework neq requires the field to exist' => [Filter::neq('sourceName', 'a'), ['sourceName' => ['$ne' => 'a', '$exists' => true]]],
            'in' => [Filter::in('year', [1, 2]), ['metadata.year' => ['$in' => [1, 2]]]],
            'gt' => [Filter::gt('year', 1), ['metadata.year' => ['$gt' => 1]]],
            'lte' => [Filter::lte('price', 2.5), ['metadata.price' => ['$lte' => 2.5]]],
            'contains any' => [Filter::containsAny('tags', ['a']), ['metadata.tags' => ['$in' => ['a']]]],
            'contains all' => [Filter::containsAll('tags', ['a', 'b']), ['$and' => [['metadata.tags' => ['$eq' => 'a']], ['metadata.tags' => ['$eq' => 'b']]]]],
            'operator lookalike field stays under metadata' => [Filter::eq('$where', 'sleep(1000)'), ['metadata.$where' => ['$eq' => 'sleep(1000)']]],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('mongoOperators')]
    public function test_mongodb_nests_custom_metadata_under_the_metadata_document(Filter $filter, array $expected): void
    {
        $this->assertSame($expected, (new MongoDBFilterCompiler())->compile($filter));
    }

    public function test_groups_compile_recursively_and_single_condition_groups_unwrap(): void
    {
        $filters = FilterGroup::anyOf(
            FilterGroup::allOf(Filter::eq('sourceType', 'file'), Filter::eq('tenant', 'acme')),
            Filter::eq('sourceName', 'x'),
        );

        $this->assertSame(['$or' => [
            ['$and' => [['sourceType' => ['$eq' => 'file']], ['metadata.tenant' => ['$eq' => 'acme']]]],
            ['sourceName' => ['$eq' => 'x']],
        ]], (new MongoDBFilterCompiler())->compile($filters));

        $this->assertSame(
            ['lang' => ['$eq' => 'en']],
            (new ChromaFilterCompiler())->compile(FilterGroup::allOf(FilterGroup::anyOf(Filter::eq('lang', 'en')))),
        );
    }

    public function test_values_that_look_like_operators_stay_values(): void
    {
        $this->assertSame(
            ['tenant' => ['$eq' => '$ne']],
            (new PineconeFilterCompiler())->compile(Filter::eq('tenant', '$ne')),
        );
        $this->assertSame(
            ['metadata.tenant' => ['$in' => ['{"$gt": ""}']]],
            (new MongoDBFilterCompiler())->compile(Filter::in('tenant', ['{"$gt": ""}'])),
        );
    }

    /**
     * @return array<string, array{object, class-string}>
     */
    public static function ownRawFragments(): array
    {
        return [
            'pinecone' => [new PineconeFilterCompiler(), PineconeVectorStore::class],
            'chroma' => [new ChromaFilterCompiler(), ChromaVectorStore::class],
            'mongodb' => [new MongoDBFilterCompiler(), MongoDBVectorStore::class],
        ];
    }

    /**
     * @param class-string $store
     */
    #[DataProvider('ownRawFragments')]
    public function test_each_compiler_passes_only_its_own_raw_fragments(
        PineconeFilterCompiler|ChromaFilterCompiler|MongoDBFilterCompiler $compiler,
        string $store,
    ): void {
        $fragment = ['$text' => ['$search' => 'php']];

        $this->assertSame(
            ['$and' => [['sourceType' => ['$eq' => 'file']], $fragment]],
            $compiler->compile(FilterGroup::allOf(Filter::eq('sourceType', 'file'), Filter::raw($store, $fragment))),
        );

        foreach ([PineconeVectorStore::class, ChromaVectorStore::class, MongoDBVectorStore::class] as $other) {
            if ($other === $store) {
                continue;
            }

            try {
                $compiler->compile(Filter::raw($other, $fragment));
                $this->fail("A {$other} fragment must be refused by the {$store} compiler.");
            } catch (VectorStoreException $exception) {
                $this->assertSame("Raw filter targets {$other}; it cannot be compiled for {$store}.", $exception->getMessage());
            }
        }
    }

    public function test_unknown_expressions_are_rejected(): void
    {
        $unknown = new class () implements FilterExpression {
            public function toArray(): array
            {
                return [];
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unsupported filter expression.');

        (new PineconeFilterCompiler())->compile($unknown);
    }
}
