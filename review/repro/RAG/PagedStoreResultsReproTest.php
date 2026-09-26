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
use PHPUnit\Framework\TestCase;

class PagedStoreResultsReproTest extends TestCase
{
    public function test_every_document_of_a_paged_generator_is_retrieved_as_a_list(): void
    {
        $pages = [[new Document('First'), new Document('Second')], [new Document('Third')]];
        $store = new class ($pages) implements VectorStoreInterface {
            /** @param Document[][] $pages */
            public function __construct(protected array $pages)
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
                foreach ($this->pages as $page) {
                    yield from $page;
                }
            }
        };

        $result = (new SimilarityRetrieval($store, new FakeEmbeddingsProvider()))->retrieve(new UserMessage('Question'));

        $this->assertSame([...$pages[0], ...$pages[1]], $result);
    }
}
