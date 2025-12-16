<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;

use function count;
use function in_array;
use function is_null;
use function trim;
use function uniqid;
use function array_chunk;

class ChromaVectorStore implements VectorStoreInterface
{
    use HasHttpClient;

    protected string $collectionId;

    /**
     * @throws HttpException
     */
    public function __construct(
        protected string $collection,
        protected string $host = 'http://localhost:8000',
        protected string $tenant = 'default_tenant',
        protected string $database = 'default_database',
        protected ?string $key = null,
        protected int $topK = 5,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri(trim($this->host, '/')."/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections/")
            ->withHeaders([
                'Content-Type' => 'application/json',
                ...(!is_null($this->key) && $this->key !== '' ? ['Authentication' => 'Bearer '.$this->key] : [])
            ]);

        $this->initialize();
    }

    /**
     * Create the collection if it doesn't exist
     *
     * @throws HttpException
     */
    protected function initialize(): void
    {
        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: trim($this->host, '/')."/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections",
                body: [
                    'name' => $this->collection,
                    'get_or_create' => true,
                ]
            )
        );

        $this->collectionId = $response->json()['id'];
    }

    /**
     * @throws HttpException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @throws HttpException
     */
    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        $this->httpClient->request(
            HttpRequest::post(
                uri: "{$this->collectionId}/delete",
                body: [
                    'where' => [
                        '$and' => [
                            ['sourceType' => ['$eq' => $sourceType]],
                            ['sourceName' => ['$eq' => $sourceName]]
                        ]
                    ]
                ]
            )
        );

        return $this;
    }

    /**
     * Delete the current collection
     *
     * @throws HttpException
     */
    public function destroy(): void
    {
        $this->httpClient->request(
            HttpRequest::delete($this->collection)
        );
    }

    /**
     * @param Document[] $documents
     * @throws HttpException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $chunks = array_chunk($documents, 100);

        foreach ($chunks as $chunk) {
            $this->httpClient->request(
                HttpRequest::post(
                    uri: "{$this->collectionId}/delete",
                    body: $this->mapDocuments($chunk)
                )
            );
        }

        return $this;
    }

    /**
     * @return iterable<Document>
     * @throws HttpException
     */
    public function similaritySearch(array $embedding): iterable
    {
        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: "{$this->collectionId}/query",
                body: [
                    'query_embeddings' => [$embedding],
                    'n_results' => $this->topK,
                    'include' => ['documents', 'metadatas', 'distances'],
                ]
            )
        )->json();

        // Map the result
        $size = count($response['ids'][0] ?? []);
        $result = [];
        for ($i = 0; $i < $size; $i++) {
            $document = new Document();
            $document->id = $response['ids'][0][$i] ?? uniqid();
            //$document->embedding = $response['embeddings'][0][$i] ?? null;
            $document->content = $response['documents'][0][$i];
            $document->sourceType = $response['metadatas'][0][$i]['sourceType'] ?? null;
            $document->sourceName = $response['metadatas'][0][$i]['sourceName'] ?? null;
            $document->score = VectorSimilarity::similarityFromDistance($response['distances'][0][$i] ?? 0.0);

            foreach (($response['metadatas'][0][$i] ?? []) as $name => $value) {
                if (!in_array($name, ['content', 'sourceType', 'sourceName', 'score', 'embedding', 'id'])) {
                    $document->addMetadata($name, $value);
                }
            }

            $result[] = $document;
        }

        return $result;
    }

    /**
     * @param Document[] $documents
     * @return array<string, array>
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
