<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Retrieval;

use Generator;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use PHPUnit\Framework\TestCase;

class SimilarityRetrievalTest extends TestCase
{
    public function test_the_query_text_is_embedded_and_searched_with_the_store_default_top_k(): void
    {
        $embeddings = new FakeEmbeddingsProvider();
        $store = new FakeVectorStore([new Document('Context')]);

        (new SimilarityRetrieval($store, $embeddings))->retrieve(new UserMessage('Città più grande?'));

        $this->assertSame(['Città più grande?'], $embeddings->getRecorded());
        $request = $store->getRecorded()[0]->request;
        $this->assertInstanceOf(SearchRequest::class, $request);
        $this->assertSame((new FakeEmbeddingsProvider())->embedText('Città più grande?'), $request->embedding);
        $this->assertNull($request->topK);
    }

    public function test_the_store_results_are_returned_as_they_are(): void
    {
        $documents = [new Document('First'), new Document('Second')];

        $result = (new SimilarityRetrieval(new FakeVectorStore($documents), new FakeEmbeddingsProvider()))
            ->retrieve(new UserMessage('Question'));

        $this->assertSame($documents, $result);
    }

    public function test_iterable_store_results_become_a_list(): void
    {
        $documents = [new Document('First'), new Document('Second')];
        $store = new class ($documents) implements VectorStoreInterface {
            /** @param Document[] $documents */
            public function __construct(protected array $documents)
            {
            }

            public function getSchema(): DocumentSchema
            {
                return DocumentSchema::default();
            }

            public function addDocument(Document $document): VectorStoreInterface
            {
                return $this;
            }

            public function addDocuments(array $documents): VectorStoreInterface
            {
                return $this;
            }

            public function delete(FilterExpression $filters): VectorStoreInterface
            {
                return $this;
            }

            public function search(SearchRequest $request): Generator
            {
                yield from $this->documents;
            }
        };

        $result = (new SimilarityRetrieval($store, new FakeEmbeddingsProvider()))->retrieve(new UserMessage('Question'));

        $this->assertSame($documents, $result);
    }
}
