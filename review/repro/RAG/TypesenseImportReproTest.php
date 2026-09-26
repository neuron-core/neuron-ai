<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\TypesenseVectorStore;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use PHPUnit\Framework\TestCase;
use Typesense\Client;

class TypesenseImportReproTest extends TestCase
{
    use RecordsVectorStoreRequests;

    protected function store(Response $importResponse): TypesenseVectorStore
    {
        $collection = ['fields' => [['name' => 'embedding', 'num_dim' => 2]]];

        return new TypesenseVectorStore(new Client([
            'api_key' => 'k',
            'nodes' => [['host' => 'ts.test', 'port' => '8108', 'protocol' => 'http']],
            'client' => $this->recordingPsrClient(
                $this->jsonResponse($collection),
                $this->jsonResponse($collection),
                $importResponse,
            ),
            'num_retries' => 0,
            'log_level' => 600,
        ]), 'docs', 2);
    }

    public function test_rejected_documents_in_a_bulk_import_are_reported(): void
    {
        $store = $this->store(new Response(200, [], "{\"success\":true}\n{\"success\":false,\"error\":\"Field `embedding` must have 2 dimensions.\",\"document\":\"...\"}"));

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Field `embedding` must have 2 dimensions.');

        $store->addDocuments([
            (new Document('Hello'))->setEmbedding([1, 0]),
            (new Document('World'))->setEmbedding([0, 1]),
        ]);
    }

    public function test_fully_successful_import_returns_normally(): void
    {
        $store = $this->store(new Response(200, [], "{\"success\":true}\n{\"success\":true}"));

        $result = $store->addDocuments([
            (new Document('Hello'))->setEmbedding([1, 0]),
            (new Document('World'))->setEmbedding([0, 1]),
        ]);

        $this->assertSame($store, $result);
        $this->assertStringEndsWith('/collections/docs/documents/import', $this->sentRequest(2)->getUri()->getPath());
    }
}
