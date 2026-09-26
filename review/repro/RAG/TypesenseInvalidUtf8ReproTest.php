<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use GuzzleHttp\Psr7\Response;
use JsonException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\TypesenseVectorStore;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use PHPUnit\Framework\TestCase;
use Typesense\Client;

use function count;
use function explode;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class TypesenseInvalidUtf8ReproTest extends TestCase
{
    use RecordsVectorStoreRequests;

    public function test_a_document_with_invalid_utf8_is_never_sent_as_a_blank_import_line(): void
    {
        $collection = fn (): Response => $this->jsonResponse(['name' => 'docs', 'fields' => [
            ['name' => 'content', 'type' => 'string'],
            ['name' => 'embedding', 'type' => 'float[]', 'num_dim' => 2],
        ]]);
        $client = new Client([
            'api_key' => 'typesense-key',
            'nodes' => [['host' => 'ts.test', 'port' => '8108', 'protocol' => 'http']],
            'client' => $this->recordingPsrClient($collection(), $collection(), new Response(200, [], "{\"success\":true}\n{\"success\":true}")),
            'num_retries' => 0,
            'log_level' => 600,
        ]);
        $store = new TypesenseVectorStore($client, 'docs', 2, '3');

        try {
            $store->addDocuments([
                (new Document("Legacy caf\xE9 menu"))->setId('legacy')->setEmbedding([0.5, 0.25]),
                (new Document('Clean'))->setId('clean')->setEmbedding([0.25, 0.5]),
            ]);
        } catch (JsonException|VectorStoreException) {
            $this->assertLessThan(3, count($this->sentTargets()), 'A rejected batch must not reach the import endpoint.');
            return;
        }

        foreach (explode("\n", (string) $this->sentRequest(2)->getBody()) as $line) {
            $this->assertNotSame('', $line, 'The document was sent as a blank NDJSON line.');
            $this->assertIsArray(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
        }
    }
}
