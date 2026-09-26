<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_get_contents;
use function json_decode;
use function range;

class MemoryVectorStoreTest extends TestCase
{
    /**
     * @param float[] $embedding
     */
    protected function document(string $content, array $embedding): Document
    {
        return (new Document($content))->setEmbedding($embedding);
    }

    /**
     * @param Document[] $documents
     * @return string[]
     */
    protected function contents(array $documents): array
    {
        return array_map(static fn (Document $document): string => $document->getContent(), $documents);
    }

    public function test_exposes_the_configured_schema_or_an_empty_default(): void
    {
        $schema = DocumentSchema::of(DocumentField::string('tenant')->filterable());

        $this->assertSame($schema, (new MemoryVectorStore(schema: $schema))->getSchema());
        $this->assertSame([], (new MemoryVectorStore())->getSchema()->fields());
    }

    public function test_real_embedding_matches_itself_with_full_similarity(): void
    {
        // embedding "Hello World!"
        $embedding = json_decode((string) file_get_contents(__DIR__ . '/../Stub/hello-world.embeddings'), true);
        $store = new MemoryVectorStore();
        $store->addDocument($this->document('Hello World!', $embedding));

        $results = $store->search(new SearchRequest($embedding));

        $this->assertSame(['Hello World!'], $this->contents($results));
        $this->assertEqualsWithDelta(1.0, $results[0]->getScore(), 1e-9);
    }

    public function test_search_orders_by_cosine_similarity_with_exact_scores(): void
    {
        $store = new MemoryVectorStore();
        $store->addDocuments([
            $this->document('orthogonal', [0, 1]),
            $this->document('opposite', [-1, 0]),
            $this->document('identical', [3, 0]),
            $this->document('diagonal', [0.5, 0.5]),
        ]);

        $results = $store->search(new SearchRequest([1, 0]));

        $this->assertSame(['identical', 'diagonal', 'orthogonal', 'opposite'], $this->contents($results));
        $this->assertEqualsWithDelta(
            [1.0, 0.7071067811865476, 0.0, -1.0],
            array_map(static fn (Document $document): ?float => $document->getScore(), $results),
            1e-12,
        );
    }

    public function test_default_top_k_is_four(): void
    {
        $store = new MemoryVectorStore();
        $store->addDocuments(array_map(fn (int $i): Document => $this->document("doc {$i}", [1, $i / 10]), range(0, 5)));

        $this->assertSame(['doc 0', 'doc 1', 'doc 2', 'doc 3'], $this->contents($store->search(new SearchRequest([1, 0]))));
    }

    public function test_request_top_k_overrides_the_store_default_and_may_exceed_the_corpus(): void
    {
        $store = new MemoryVectorStore(topK: 1);
        $store->addDocuments([$this->document('a', [1, 0]), $this->document('b', [0, 1])]);

        $this->assertSame(['a'], $this->contents($store->search(new SearchRequest([1, 0]))));
        $this->assertSame(['a', 'b'], $this->contents($store->search(new SearchRequest([1, 0], topK: 2))));
        $this->assertSame(['a', 'b'], $this->contents($store->search(new SearchRequest([1, 0], topK: 50))));
    }

    public function test_equally_similar_documents_keep_insertion_order(): void
    {
        $store = new MemoryVectorStore(topK: 2);
        $store->addDocuments([
            $this->document('first', [1, 0]),
            $this->document('second', [2, 0]),
            $this->document('third', [5, 0]),
        ]);

        $this->assertSame(['first', 'second'], $this->contents($store->search(new SearchRequest([1, 0]))));
    }

    public function test_zero_vectors_score_zero_instead_of_failing(): void
    {
        $store = new MemoryVectorStore();
        $store->addDocument($this->document('zero', [0, 0]));

        $this->assertSame(0.0, $store->search(new SearchRequest([1, 0]))[0]->getScore());
    }

    public function test_search_rejects_embeddings_of_a_different_dimension(): void
    {
        $store = new MemoryVectorStore();
        $store->addDocument($this->document('three', [1, 0, 0]));

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Vectors must have the same length to apply cosine similarity.');

        $store->search(new SearchRequest([1, 0]));
    }

    public function test_search_on_empty_store_returns_no_documents(): void
    {
        $this->assertSame([], (new MemoryVectorStore())->search(new SearchRequest([1, 0])));
    }

    public function test_results_carry_every_stored_attribute(): void
    {
        $document = $this->document('Hello World!', [1, 0])
            ->setId('doc-1')
            ->setSourceType('file')
            ->setSourceName('hello.txt')
            ->setMetadata(['customProperty' => 'customValue', 'nested' => ['a' => 1]]);

        $store = new MemoryVectorStore();
        $store->addDocuments([$document]);
        $result = $store->search(new SearchRequest([1, 0]))[0];

        $this->assertSame('doc-1', $result->getId());
        $this->assertSame('file', $result->getSourceType());
        $this->assertSame('hello.txt', $result->getSourceName());
        $this->assertSame([1.0, 0.0], $result->getEmbedding());
        $this->assertSame(['customProperty' => 'customValue', 'nested' => ['a' => 1]], $result->getMetadata());
    }

    public function test_search_scores_do_not_mutate_stored_or_previous_documents(): void
    {
        $source = (new Document('Document'))
            ->setEmbedding([1, 0])
            ->setScore(0.25);
        $store = new MemoryVectorStore();
        $store->addDocument($source);

        $first = $store->search(new SearchRequest([1, 0]))[0];
        $firstScore = $first->getScore();
        $store->search(new SearchRequest([0, 1]));

        $this->assertSame(0.25, $source->getScore());
        $this->assertSame($firstScore, $first->getScore());
        $this->assertNotSame($source, $first);
    }

    public function test_stored_documents_are_isolated_from_later_changes_to_the_originals_and_results(): void
    {
        $source = $this->document('original', [1, 0])->addMetadata('tag', 'a');
        $store = new MemoryVectorStore();
        $store->addDocument($source);

        $source->addMetadata('tag', 'changed');
        $store->search(new SearchRequest([1, 0]))[0]->addMetadata('tag', 'tampered');

        $this->assertSame(['tag' => 'a'], $store->search(new SearchRequest([1, 0]))[0]->getMetadata());
    }

    public function test_invalid_batch_is_rejected_without_storing_any_document(): void
    {
        $schema = DocumentSchema::of(DocumentField::string('tenant')->required()->filterable());
        $store = new MemoryVectorStore(schema: $schema);

        try {
            $store->addDocuments([
                $this->document('valid', [1, 0])->addMetadata('tenant', 'acme'),
                $this->document('invalid', [1, 0])->setId('bad'),
            ]);
            $this->fail('A document missing a required field must be rejected.');
        } catch (DocumentSchemaException $exception) {
            $this->assertSame('Document bad is missing required metadata field "tenant".', $exception->getMessage());
        }

        $this->assertSame([], $store->search(new SearchRequest([1, 0])));
    }

    public function test_document_without_embedding_is_rejected(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Document doc-1 must have an embedding before it can be stored.');

        (new MemoryVectorStore())->addDocument((new Document('no vector'))->setId('doc-1'));
    }

    public function test_search_filters_before_ranking(): void
    {
        $schema = DocumentSchema::of(DocumentField::string('tenant')->required()->filterable());
        $store = new MemoryVectorStore(topK: 1, schema: $schema);
        $store->addDocuments([
            $this->document('best but other tenant', [1, 0])->addMetadata('tenant', 'globex'),
            $this->document('worse but same tenant', [0.2, 1])->addMetadata('tenant', 'acme'),
        ]);

        $results = $store->search(new SearchRequest([1, 0], Filter::eq('tenant', 'acme')));

        $this->assertSame(['worse but same tenant'], $this->contents($results));
    }

    public function test_search_rejects_filters_on_undeclared_fields_even_when_empty(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Filter field "tenant" is not declared in the vector store document schema.');

        (new MemoryVectorStore())->search(new SearchRequest([1, 0], Filter::eq('tenant', 'acme')));
    }

    public function test_search_refuses_raw_filters_it_cannot_evaluate(): void
    {
        $store = new MemoryVectorStore();
        $store->addDocument($this->document('a', [1, 0]));

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Raw filter targets ' . QdrantVectorStore::class . '; it cannot be evaluated in PHP.');

        $store->search(new SearchRequest([1, 0], Filter::raw(QdrantVectorStore::class, ['key' => 'x'])));
    }

    public function test_delete_by_source_removes_only_matching_documents(): void
    {
        $store = new MemoryVectorStore();
        $store->addDocuments([
            $this->document('web a', [1, 0])->setSourceType('web')->setSourceName('page-a'),
            $this->document('web b', [0, 1])->setSourceType('web')->setSourceName('page-b'),
            $this->document('file', [0.5, 0.5])->setSourceType('file')->setSourceName('doc.txt'),
        ]);

        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'web'), Filter::eq('sourceName', 'page-a')));
        $this->assertSame(['file', 'web b'], $this->contents($store->search(new SearchRequest([0.4, 0.6]))));

        $store->delete(Filter::eq('sourceType', 'web'));
        $this->assertSame(['file'], $this->contents($store->search(new SearchRequest([1, 0]))));
    }

    public function test_delete_matching_nothing_keeps_every_document(): void
    {
        $store = new MemoryVectorStore();
        $store->addDocuments([$this->document('a', [1, 0]), $this->document('b', [0, 1])]);

        $store->delete(Filter::eq('sourceType', 'missing'));

        $this->assertCount(2, $store->search(new SearchRequest([1, 0])));
    }

    public function test_delete_rejects_invalid_filters_without_removing_documents(): void
    {
        $store = new MemoryVectorStore();
        $store->addDocument($this->document('a', [1, 0]));

        try {
            $store->delete(Filter::gt('sourceType', 1));
            $this->fail('A range filter on a string field must be rejected.');
        } catch (DocumentSchemaException $exception) {
            $this->assertSame('Filter operator gt requires a numeric field; "sourceType" is string.', $exception->getMessage());
        }

        $this->assertCount(1, $store->search(new SearchRequest([1, 0])));
    }
}
