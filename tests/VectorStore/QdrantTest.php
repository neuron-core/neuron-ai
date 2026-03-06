<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use GuzzleHttp\Exception\GuzzleException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\Tests\Traits\CheckOpenPort;
use PHPUnit\Framework\TestCase;

use function sprintf;

class QdrantTest extends TestCase
{
    use CheckOpenPort;

    public const SERVICE_PORT = 6333;
    public const COLLECTION_NAME = 'neuron-ai';

    public const SOURCE_TYPE = 'manual';
    public const SOURCE_NAME = 'manual';

    protected QdrantVectorStore $store;

    /**
     * @throws GuzzleException
     */
    public function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', self::SERVICE_PORT)) {
            $this->markTestSkipped("Port ".self::SERVICE_PORT." is not open. Skipping test.");
        }

        $this->store = new QdrantVectorStore(
            collectionUrl: sprintf("http://127.0.0.1:%d/collections/%s", self::SERVICE_PORT, self::COLLECTION_NAME),
            dimension: 3,
        );
    }

    public function tearDown(): void
    {
        $this->store->destroy();
    }

    /**
     * @throws GuzzleException
     */
    public function test_add_document_and_search(): void
    {
        $document = new Document('Hello World!');
        $document->addMetadata('customProperty', 'customValue');
        $document->embedding = [1, 2, 3];

        $this->store->addDocument($document);

        $results = $this->store->similaritySearch([1, 2, 3]);

        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->metadata['customProperty'], $results[0]->metadata['customProperty']);
    }

    /**
     * @throws GuzzleException
     */
    public function test_add_multiple_document_and_search(): void
    {
        $document = new Document('Hello!');
        $document->addMetadata('customProperty', 'customValue');
        $document->embedding = [1, 2, 3];

        $document2 = new Document('Hello 2!');
        $document2->embedding = [3, 4, 5];

        $this->store->addDocuments([$document, $document2]);

        $results = $this->store->similaritySearch([1, 2, 3]);

        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->metadata['customProperty'], $results[0]->metadata['customProperty']);
    }

    /**
     * @throws GuzzleException
     */
    public function test_delete_documents(): void
    {
        $document = new Document('Hello!');
        $document->sourceType = self::SOURCE_TYPE;
        $document->sourceName = self::SOURCE_NAME;
        $document->embedding = [1, 2, 3];

        $document2 = new Document('Hello 2!');
        $document2->sourceType = self::SOURCE_TYPE;
        $document2->sourceName = self::SOURCE_NAME;
        $document2->embedding = [3, 4, 5];

        $this->store->addDocuments([$document, $document2]);
        $this->store->deleteBySource(self::SOURCE_TYPE, self::SOURCE_NAME);

        $results = $this->store->similaritySearch([1, 2, 3]);
        $this->assertCount(0, $results);
    }

    /**
     * @throws GuzzleException
     */
    public function test_delete_by_type(): void
    {
        $document1 = new Document('Hello type A!');
        $document1->sourceType = 'web';
        $document1->sourceName = 'page-a';
        $document1->embedding = [1, 2, 3];

        $document2 = new Document('Hello type B!');
        $document2->sourceType = 'web';
        $document2->sourceName = 'page-b';
        $document2->embedding = [3, 4, 5];

        $document3 = new Document('Hello type C!');
        $document3->sourceType = 'file';
        $document3->sourceName = 'doc.txt';
        $document3->embedding = [2, 2, 2];

        $this->store->addDocuments([$document1, $document2, $document3]);
        $this->store->deleteByType('web');

        $results = $this->store->similaritySearch([1, 2, 3]);
        $this->assertCount(1, $results);
        $this->assertEquals('file', $results[0]->getSourceType());
    }
}
