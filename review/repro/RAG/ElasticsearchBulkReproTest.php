<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use PHPUnit\Framework\TestCase;

use function array_map;
use function range;
use function substr_count;
use function trim;

class ElasticsearchBulkReproTest extends TestCase
{
    use RecordsVectorStoreRequests;

    protected function es(array $body = []): Response
    {
        return $this->jsonResponse($body, 200, ['X-Elastic-Product' => 'Elasticsearch']);
    }

    public function test_each_bulk_request_indexes_only_its_own_chunk(): void
    {
        $mapping = ['docs' => ['mappings' => ['embedding' => ['mapping' => ['embedding' => ['dims' => 2]]]]]];
        $client = ClientBuilder::create()
            ->setHosts(['http://es.test:9200'])
            ->setHttpClient($this->recordingPsrClient($this->es(), $this->es($mapping), $this->es(), $this->es(), $this->es(), $this->es()))
            ->build();
        $store = new ElasticsearchVectorStore($client, 'docs');

        $store->addDocuments(array_map(
            static fn (int $i): Document => (new Document("doc {$i}"))->setEmbedding([1, 0]),
            range(1, 101),
        ));

        $bulkLines = static fn (string $body): int => substr_count(trim($body), "\n") + 1;
        $this->assertSame('POST http://es.test:9200/_bulk', $this->sentTargets()[2]);
        $this->assertSame('POST http://es.test:9200/_bulk', $this->sentTargets()[4]);
        $this->assertSame(200, $bulkLines((string) $this->sentRequest(2)->getBody()));
        $this->assertSame(2, $bulkLines((string) $this->sentRequest(4)->getBody()));
        $this->assertStringContainsString('doc 101', (string) $this->sentRequest(4)->getBody());
        $this->assertStringNotContainsString('"doc 1"', (string) $this->sentRequest(4)->getBody());
    }
}
