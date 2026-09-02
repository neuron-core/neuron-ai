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
use NeuronAI\RAG\VectorStore\Compilers\QdrantFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use function array_chunk;
use function array_map;
use function is_null;

class QdrantVectorStore implements VectorStoreInterface
{
    use HasHttpClient;
    use HasDocumentSchema;

    /**
     * @throws HttpException
     */
    public function __construct(
        protected string $collectionUrl, // like http://localhost:6333/collections/neuron-ai/
        protected ?string $key = null,
        protected int $topK = 5,
        protected int $dimension = 1024,
        ?HttpClientInterface $httpClient = null,
        ?DocumentSchema $schema = null,
    ) {
        $this->initializeSchema($schema);
        $this->httpClient = ($httpClient ?? new CurlHttpClient())
            ->withBaseUri($this->collectionUrl)
            ->withHeaders([
                'Content-Type' => 'application/json',
                ...(!is_null($this->key) && $this->key !== '' ? ['api-key' => $this->key] : []),
            ]);

        $this->initialize();
    }

    /**
     * @throws HttpException
     */
    protected function initialize(): void
    {
        $response = $this->httpClient->request(
            HttpRequest::get(uri: 'exists')
        )->json();

        if ($response['result']['exists']) {
            return;
        }

        $this->createCollection();
    }

    /**
     * @throws HttpException
     */
    public function destroy(): void
    {
        $this->httpClient->request(HttpRequest::delete(uri: ''));
    }

    /**
     * @throws HttpException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * Bulk save documents.
     *
     * @param Document[] $documents
     * @throws HttpException
     * @throws VectorStoreException|JsonException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->validateDocuments($documents);
        $points = array_map(fn (Document $document): array => [
            'id' => (string) $document->getId(),
            'payload' => [
                'content' => $document->getContent(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                ...MetadataMapper::toStorage($document, $this->schema),
            ],
            'vector' => $document->getEmbedding(),
        ], $documents);

        $chunks = array_chunk($points, 100);

        foreach ($chunks as $chunk) {
            $this->httpClient->request(
                HttpRequest::put(uri: 'points?wait=true', body: ['points' => $chunk])
            );
        }

        return $this;
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
                uri: 'points/delete?wait=true',
                body: [
                    'filter' => ['must' => (new QdrantFilterCompiler())->compile($filters)],
                ]
            )
        );

        return $this;
    }

    /**
     * @throws HttpException
     * @throws DocumentSchemaException|VectorStoreException
     */
    public function search(SearchRequest $request): iterable
    {
        if ($request->filters instanceof FilterExpression) {
            $this->validateFilters($request->filters);
        }

        $body = [
            'query' => [
                'recommend' => ['positive' => [$request->embedding]],
            ],
            'limit' => $request->topK ?? $this->topK,
            'with_payload' => true,
            'with_vector' => true,
        ];

        if ($request->filters instanceof FilterExpression) {
            $body['filter'] = ['must' => (new QdrantFilterCompiler())->compile($request->filters)];
        }

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'points/query',
                body: $body
            )
        )->json();

        return array_map(function (array $item): Document {
            $document = new Document($item['payload']['content']);
            $document->setId($item['id'])
                ->setEmbedding($item['vector'])
                ->setSourceType($item['payload']['sourceType'])
                ->setSourceName($item['payload']['sourceName'])
                ->setScore($item['score']);

            MetadataMapper::hydrate($document, $item['payload']);

            return $document;
        }, $response['result']['points']);
    }

    /**
     * @throws HttpException
     */
    protected function createCollection(): void
    {
        $this->httpClient->request(
            HttpRequest::put(
                uri: '',
                body: [
                    'vectors' => [
                        'size' => $this->dimension,
                        'distance' => 'Cosine',
                    ],
                ],
            )
        );
    }
}
