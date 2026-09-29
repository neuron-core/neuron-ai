<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\TypesenseVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\Support\CheckOpenPort;
use PHPUnit\Framework\TestCase;
use Typesense\Client;

use function array_map;
use function bin2hex;
use function file_get_contents;
use function json_decode;
use function random_bytes;
use function uniqid;

class TypesenseTest extends TestCase
{
    use CheckOpenPort;

    protected Client $client;

    protected int $vectorDimension = 1024;

    protected array $embedding;

    protected function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', 8108)) {
            $this->markTestSkipped('Port 8108 is not open. Skipping test.');
        }

        // see getting started
        // https://typesense.org/docs/guide/install-typesense.html#option-2-local-machine-self-hosting

        $this->client = new Client([
            'api_key' => 'xyz',
            'nodes' => [
                [
                    'host' => '127.0.0.1',
                    'port' => '8108',
                    'protocol' => 'http',
                ],
            ],
        ]);

        // embedding "Hello World!"
        $this->embedding = json_decode(file_get_contents(__DIR__ . '/../Stub/hello-world.embeddings'), true);
    }

    public function test_typesense_instance(): void
    {
        $store = new TypesenseVectorStore($this->client, 'test', $this->vectorDimension);
        $this->assertInstanceOf(VectorStoreInterface::class, $store);
    }

    public function test_add_document_and_search(): void
    {
        $store = new TypesenseVectorStore($this->client, 'test', $this->vectorDimension);

        $document = new Document('Hello World!');
        $document->addMetadata('customProperty', 'customValue');
        $document->setEmbedding($this->embedding);

        $store->addDocument($document);

        $results = $store->search(new SearchRequest($this->embedding));

        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->getMetadata()['customProperty'], $results[0]->getMetadata()['customProperty']);
    }

    public function test_search_results_keep_the_stored_id(): void
    {
        $collection = 'test_ids_' . uniqid();
        $store = new TypesenseVectorStore($this->client, $collection, $this->vectorDimension);
        $document = new Document('Hello World!');
        $document->setEmbedding($this->embedding);

        try {
            $store->addDocument($document);

            $this->assertSame($document->getId(), $store->search(new SearchRequest($this->embedding))[0]->getId());
        } finally {
            $this->client->collections[$collection]->delete();
        }
    }

    public function test_add_documents(): void
    {
        $store = new TypesenseVectorStore($this->client, 'test', $this->vectorDimension);

        $document = new Document('Hello World!');
        $document->addMetadata('customProperty', 'customValue');
        $document->setEmbedding($this->embedding);

        $store->addDocuments([$document]);

        $results = $store->search(new SearchRequest($this->embedding));

        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->getMetadata()['customProperty'], $results[0]->getMetadata()['customProperty']);
    }

    public function test_delete_documents(): void
    {
        $store = new TypesenseVectorStore($this->client, 'test', $this->vectorDimension);

        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'manual'), Filter::eq('sourceName', 'manual')));

        $results = $store->search(new SearchRequest($this->embedding));
        $this->assertCount(0, $results);
    }

    public function test_delete_by_type(): void
    {
        $store = new TypesenseVectorStore($this->client, 'test-'.bin2hex(random_bytes(5)), $this->vectorDimension);

        $document1 = new Document('Hello type A!');
        $document1->setEmbedding($this->embedding);
        $document1->setSourceType('web');
        $document1->setSourceName('page-a');

        $document2 = new Document('Hello type B!');
        $document2->setEmbedding($this->embedding);
        $document2->setSourceType('file');
        $document2->setSourceName('doc.txt');

        $store->addDocuments([$document1, $document2]);
        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'web')));

        $results = $store->search(new SearchRequest($this->embedding));
        $this->assertCount(1, $results);
        $this->assertSame('file', $results[0]->getSourceType());
    }

    public function test_documents_the_server_rejects_are_reported_and_the_accepted_ones_kept(): void
    {
        $collection = 'test-'.bin2hex(random_bytes(5));
        $store = new TypesenseVectorStore($this->client, $collection, $this->vectorDimension);

        try {
            $store->addDocuments([
                (new Document('Accepted'))->setId('accepted')->setEmbedding($this->embedding),
                (new Document('Too short'))->setId('too-short')->setEmbedding([0.1, 0.2, 0.3]),
            ]);
            $this->fail('A document the server rejects must be reported.');
        } catch (VectorStoreException $exception) {
            $this->assertSame(
                "Typesense rejected 1 of 2 documents: [too-short] Field `embedding` must have {$this->vectorDimension} dimensions.",
                $exception->getMessage()
            );
        } finally {
            $results = $store->search(new SearchRequest($this->embedding));
            $this->client->collections[$collection]->delete();
        }

        $this->assertSame(['Accepted'], array_map(static fn (Document $document): string => $document->getContent(), $results));
    }

    public function test_a_repeated_id_replaces_the_stored_document(): void
    {
        $collection = 'test-'.bin2hex(random_bytes(5));
        $store = new TypesenseVectorStore($this->client, $collection, $this->vectorDimension);
        $contents = fn (): array => array_map(
            static fn (Document $document): string => $document->getContent(),
            $store->search(new SearchRequest($this->embedding))
        );

        try {
            $store->addDocument((new Document('First'))->setId('same')->setEmbedding($this->embedding));
            $store->addDocuments([(new Document('Second'))->setId('same')->setEmbedding($this->embedding)]);
            $afterBulk = $contents();
            $store->addDocument((new Document('Third'))->setId('same')->setEmbedding($this->embedding));
            $afterSingle = $contents();
        } finally {
            $this->client->collections[$collection]->delete();
        }

        $this->assertSame([['Second'], ['Third']], [$afterBulk, $afterSingle]);
    }
}
