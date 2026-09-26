<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Tests\Tools\Stub\RecordingRetrieval;
use NeuronAI\Tools\Toolkits\RetrievalTool;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function json_decode;

class RetrievalEmbeddingLeakTest extends TestCase
{
    public function test_embeddings_never_reach_the_model(): void
    {
        $document = (new Document('Refunds are accepted within 30 days.'))->setEmbedding(array_fill(0, 1536, 0.123456789));
        $tool = (new RetrievalTool(new RecordingRetrieval([$document])))->setInputs(['query' => 'refund']);

        $tool->execute();

        $result = json_decode((string) $tool->getResult(), true);
        $this->assertArrayNotHasKey('embedding', $result[0]);
        $this->assertSame('Refunds are accepted within 30 days.', $result[0]['content']);
    }

    public function test_documents_retrieved_from_a_memory_vector_store_carry_no_embedding(): void
    {
        $embeddings = new FakeEmbeddingsProvider(1536);
        $store = new MemoryVectorStore();
        $store->addDocument($embeddings->embedDocument((new Document('Refunds are accepted within 30 days.'))->setSourceName('faq.md')));
        $tool = (new RetrievalTool(new SimilarityRetrieval($store, $embeddings)))->setInputs(['query' => 'refund']);

        $tool->execute();

        $result = json_decode((string) $tool->getResult(), true);
        $this->assertArrayNotHasKey('embedding', $result[0]);
        $this->assertSame('Refunds are accepted within 30 days.', $result[0]['content']);
        $this->assertSame('faq.md', $result[0]['sourceName']);
        $this->assertIsFloat($result[0]['score']);
    }
}
