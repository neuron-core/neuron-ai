<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;

class ChromaVectorStore implements VectorStoreInterface
{
    protected Client $client;

    protected string $collectionId;

    /**
     * @throws GuzzleException
     */
    public function __construct(
        string $collection,
        protected string $host = 'http://localhost:8000',
        protected string $tenant = 'default_tenant',
        protected string $database = 'default_database',
        protected ?string $key = null,
        protected int $topK = 5,
    ) {
        $this->initialize($collection);
    }

    /**
     * Create the collection if it doesn't exist
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function initialize(string $collection): void
    {
        try {
            $response = $this->client()->get($collection)->getBody()->getContents();
        } catch (\Exception $e) {
            $client = new Client(['base_uri' => \trim($this->host, '/').'/api/v2/tenants/'.$this->tenant.'/databases/'.$this->database.'/']);
            $response = $client->post('collections', [
                RequestOptions::JSON => ['name' => $collection]
            ])->getBody()->getContents();
        }

        $this->collectionId = \json_decode($response, true)['id'];
    }

    protected function client(): Client
    {
        return $this->client ?? $this->client = new Client([
            'base_uri' => \trim($this->host, '/')."/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections/",
            'headers' => [
                'Content-Type' => 'application/json',
            ]
        ]);
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        $this->client()->post("{$this->collectionId}/delete", [
            RequestOptions::JSON => [
                'where' => [
                    'sourceType' => $sourceType,
                    'sourceName' => $sourceName,
                ]
            ]
        ]);

        return $this;
    }

    /**
     * @throws GuzzleException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->client()->post("{$this->collectionId}/add", [
            RequestOptions::JSON => $this->mapDocuments($documents),
        ]);

        return $this;
    }

    /**
     * @throws GuzzleException
     */
    public function similaritySearch(array $embedding): iterable
    {
        $response = $this->client()->post("{$this->collectionId}/query", [
            RequestOptions::JSON => [
                'query_embeddings' => $embedding,
                'n_results' => $this->topK,
            ]
        ])->getBody()->getContents();

        $response = \json_decode($response, true);

        // Map the result
        $size = \count($response['distances']);
        $result = [];
        for ($i = 0; $i < $size; $i++) {
            $document = new Document();
            $document->id = $response['ids'][$i] ?? \uniqid();
            $document->embedding = $response['embeddings'][$i];
            $document->content = $response['documents'][$i];
            $document->sourceType = $response['metadatas'][$i]['sourceType'] ?? null;
            $document->sourceName = $response['metadatas'][$i]['sourceName'] ?? null;
            $document->score = VectorSimilarity::similarityFromDistance($response['distances'][$i]);

            foreach ($response['metadatas'][$i] as $name => $value) {
                if (!\in_array($name, ['content', 'sourceType', 'sourceName', 'score', 'embedding', 'id'])) {
                    $document->addMetadata($name, $value);
                }
            }

            $result[] = $document;
        }

        return $result;
    }

    /**
     * @param Document[] $documents
     */
    protected function mapDocuments(array $documents): array
    {
        $payload = [
            'ids' => [],
            'documents' => [],
            'embeddings' => [],
            'metadatas' => [],
        ];

        foreach ($documents as $document) {
            $payload['ids'][] = (string) $document->getId();
            $payload['documents'][] = $document->getContent();
            $payload['embeddings'][] = $document->getEmbedding();
            $payload['metadatas'][] = [
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                ...$document->metadata,
            ];
        }

        return $payload;
    }
}
