<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Retrieval\HybridRetrieval;
use NeuronAI\RAG\VectorStore\HybridVectorStoreInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PHPUnit\Framework\TestCase;

class HybridRetrievalTest extends TestCase
{
    public function test_it_passes_query_and_embedding_to_the_vector_store(): void
    {
        $embeddings = new class () implements EmbeddingsProviderInterface {
            public function embedText(string $text): array
            {
                return [1.0, 2.0, 3.0];
            }

            public function embedDocument(Document $document): Document
            {
                return $document;
            }

            public function embedDocuments(array $documents): array
            {
                return $documents;
            }
        };
        $store = new class () implements HybridVectorStoreInterface {
            public string $query = '';
            public array $embedding = [];

            public function hybridSearch(string $query, array $embedding): array
            {
                $this->query = $query;
                $this->embedding = $embedding;
                return [new Document('result')];
            }

            public function addDocument(Document $document): VectorStoreInterface
            {
                return $this;
            }

            public function addDocuments(array $documents): VectorStoreInterface
            {
                return $this;
            }

            public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
            {
                return $this;
            }

            public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
            {
                return $this;
            }

            public function similaritySearch(array $embedding): iterable
            {
                return [];
            }
        };

        $documents = (new HybridRetrieval($store, $embeddings))->retrieve(new UserMessage('exact query'));

        $this->assertSame('exact query', $store->query);
        $this->assertSame([1.0, 2.0, 3.0], $store->embedding);
        $this->assertSame('result', $documents[0]->getContent());
    }
}
