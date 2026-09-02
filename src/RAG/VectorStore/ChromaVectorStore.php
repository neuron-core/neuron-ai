<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use JsonException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\HttpClient\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\Compilers\ChromaFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use function array_chunk;
use function count;
use function is_null;
use function trim;
use function uniqid;

class ChromaVectorStore implements VectorStoreInterface
{
    use HasHttpClient;
    use HasDocumentSchema;

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
        ?DocumentSchema $schema = null,
    ) {
        $this->initializeSchema($schema);
        $this->httpClient = ($httpClient ?? new CurlHttpClient())
            ->withBaseUri(trim($this->host, '/')."/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections/")
            ->withHeaders([
                'Content-Type' => 'application/json',
                ...(!is_null($this->key) && $this->key !== '' ? ['Authentication' => 'Bearer '.$this->key] : []),
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
                uri: '',
                body: [
                    'name' => $this->collection,
                    'get_or_create' => true,
                ]
            )
        )->json();

        $this->collectionId = $response['id'];
    }

    /**
     * @throws HttpException
     * @throws VectorStoreException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @throws HttpException
     * @throws DocumentSchemaException
     */
    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);
        $this->httpClient->request(
            HttpRequest::post(
                uri: "{$this->collectionId}/delete",
                body: ['where' => (new ChromaFilterCompiler())->compile($filters)]
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
            HttpRequest::delete(uri: $this->collection)
        );
    }

    /**
     * @param Document[] $documents
     * @throws HttpException|VectorStoreException
     * @throws JsonException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->validateDocuments($documents);
        $chunks = array_chunk($documents, 100);

        foreach ($chunks as $chunk) {
            $this->httpClient->request(
                HttpRequest::post(
                    uri: "{$this->collectionId}/add",
                    body: $this->mapDocuments($chunk)
                )
            );
        }

        return $this;
    }

    /**
     * @return iterable<Document>
     * @throws HttpException
     * @throws DocumentSchemaException|VectorStoreException
     */
    public function search(SearchRequest $request): iterable
    {
        if ($request->filters instanceof FilterExpression) {
            $this->validateFilters($request->filters);
        }

        $body = [
            'query_embeddings' => [$request->embedding],
            'n_results' => $request->topK ?? $this->topK,
            'include' => ['documents', 'metadatas', 'distances'],
        ];

        if ($request->filters instanceof FilterExpression) {
            $body['where'] = (new ChromaFilterCompiler())->compile($request->filters);
        }

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: "{$this->collectionId}/query",
                body: $body
            )
        )->json();

        // Map the result
        $size = count($response['ids'][0] ?? []);
        $result = [];
        for ($i = 0; $i < $size; $i++) {
            $document = (new Document($response['documents'][0][$i]))
                ->setId($response['ids'][0][$i] ?? uniqid())
                ->setSourceType($response['metadatas'][0][$i]['sourceType'] ?? 'manual')
                ->setSourceName($response['metadatas'][0][$i]['sourceName'] ?? 'manual')
                ->setScore(VectorSimilarity::similarityFromDistance($response['distances'][0][$i] ?? 0.0));

            MetadataMapper::hydrate($document, $response['metadatas'][0][$i] ?? []);

            $result[] = $document;
        }

        return $result;
    }

    /**
     * @param Document[] $documents
     * @return array<string, array>
     * @throws JsonException
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
                ...MetadataMapper::toStorage($document, $this->schema),
            ];
        }

        return $payload;
    }
}
