<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Compilers\MeilisearchFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use Exception;

use function array_map;
use function is_null;
use function min;
use function range;
use function usleep;
use function uniqid;
use function array_chunk;

class MeilisearchVectorStore implements VectorStoreInterface
{
    use HasHttpClient;
    use HasDocumentSchema;

    /**
     * @throws HttpException
     */
    public function __construct(
        protected string $indexUid,
        protected string $host = 'http://localhost:7700',
        ?string $key = null,
        protected string $embedder = 'default',
        protected int $topK = 5,
        protected int $dimension = 1024,
        ?HttpClientInterface $httpClient = null,
        ?DocumentSchema $schema = null,
    ) {
        $this->initializeSchema($schema);
        $this->httpClient = ($httpClient ?? new CurlHttpClient())
            ->withBaseUri($host)
            ->withHeaders([
                'Content-Type' => 'application/json',
                ...(is_null($key) ? [] : ['Authorization' => "Bearer {$key}"]),
            ]);

        try {
            $this->httpClient->request(HttpRequest::get(uri: "indexes/{$this->indexUid}"));
        } catch (Exception) {
            $this->createIndex();
        }

        $this->configureIndex();
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
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->validateDocuments($documents);
        $chunks = array_chunk($documents, 100);

        foreach ($chunks as $chunk) {
            $this->httpClient->request(
                HttpRequest::put(
                    uri: "indexes/{$this->indexUid}/documents",
                    body: array_map(fn (Document $document): array => [
                        'id' => $document->getId(),
                        'content' => $document->getContent(),
                        'sourceType' => $document->getSourceType(),
                        'sourceName' => $document->getSourceName(),
                        ...MetadataMapper::toStorage($document, $this->schema),
                        '_vectors' => [
                            'default' => [
                                'embeddings' => $document->getEmbedding(),
                                'regenerate' => false,
                            ],
                        ],
                    ], $chunk),
                )
            );
        }

        return $this;
    }

    /**
     * @throws HttpException
     */
    public function delete(FilterGroup $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);
        $this->httpClient->request(
            HttpRequest::post(
                uri: "/indexes/{$this->indexUid}/documents/delete",
                body: ['filter' => (new MeilisearchFilterCompiler())->compile($filters)]
            )
        );

        return $this;
    }

    /**
     * @throws HttpException
     */
    public function search(SearchRequest $request): iterable
    {
        if ($request->filters instanceof FilterGroup) {
            $this->validateFilters($request->filters);
        }

        $body = [
            'vector' => $request->embedding,
            'limit' => min($request->topK ?? $this->topK, 20),
            'retrieveVectors' => true,
            'showRankingScore' => true,
            'hybrid' => [
                'semanticRatio' => 1.0,
                'embedder' => $this->embedder,
            ],
        ];

        if ($request->filters instanceof FilterGroup) {
            $body['filter'] = (new MeilisearchFilterCompiler())->compile($request->filters);
        }

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: "/indexes/{$this->indexUid}/search",
                body: $body
            )
        )->json();

        return array_map(function (array $item): Document {
            $document = new Document($item['content']);
            $document->setId($item['id'] ?? uniqid())
                ->setSourceType($item['sourceType'] ?? 'manual')
                ->setSourceName($item['sourceName'] ?? 'manual')
                // Meilisearch returns the embedder's vectors as a list of embeddings.
                ->setEmbedding($item['_vectors']['default']['embeddings'][0])
                ->setScore($item['_rankingScore']);

            MetadataMapper::hydrate($document, $item);

            return $document;
        }, $response['hits']);
    }

    /**
     * @throws HttpException
     */
    protected function createIndex(): void
    {
        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'indexes',
                body: [
                    'uid' => $this->indexUid,
                    'primaryKey' => 'id',
                ]
            )
        )->json();

        $this->waitForTask($response['taskUid']);

    }

    protected function configureIndex(): void
    {
        $response = $this->httpClient->request(
            HttpRequest::patch(
                uri: "indexes/{$this->indexUid}/settings/embedders",
                body: [
                    $this->embedder => [
                        'dimensions' => $this->dimension,
                        'source' => 'userProvided',
                        'binaryQuantized' => false,
                    ],
                ]
            )
        )->json();

        $this->waitForTask($response['taskUid']);

        $filterableAttributes = ['sourceType', 'sourceName'];
        foreach ($this->schema->fields() as $field) {
            if ($field->isFilterable()) {
                $filterableAttributes[] = $field->getName();
            }
        }

        $response = $this->httpClient->request(
            HttpRequest::put(
                uri: "indexes/{$this->indexUid}/settings/filterable-attributes",
                body: $filterableAttributes,
            )
        )->json();

        $this->waitForTask($response['taskUid']);
    }

    protected function waitForTask(int $taskUid): void
    {
        foreach (range(1, 10) as $i) {
            try {
                $task = $this->httpClient->request(
                    HttpRequest::get('tasks/' . $taskUid)
                )->json();
                if ($task['status'] === 'succeeded') {
                    return;
                }
                usleep(500 * 1000);
            } catch (Exception) {
                usleep(500 * 1000);
            }
        }
    }
}
