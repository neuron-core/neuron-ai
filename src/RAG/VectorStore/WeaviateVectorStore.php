<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;

use function array_chunk;
use function array_map;
use function implode;
use function in_array;
use function is_null;
use function sprintf;
use function ucfirst;
use function count;
use function is_array;
use function strcasecmp;

class WeaviateVectorStore implements VectorStoreInterface
{
    use HasHttpClient;

    /**
     * @throws HttpException
     */
    public function __construct(
        protected string $collection,
        protected string $host = 'http://localhost:8080',
        protected ?string $key = null,
        protected int $topK = 5,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri($host)
            ->withHeaders([
                'Content-Type' => 'application/json',
                ...(!is_null($this->key) && $this->key !== '' ? ['Authorization' => 'Bearer '.$this->key] : []),
            ]);

        $this->initialize();
    }

    /**
     * @throws HttpException
     */
    protected function initialize(): void
    {
        if ($this->collectionExists()) {
            return;
        }

        $this->createCollection();
    }

    /**
     * @throws HttpException
     */
    public function destroy(): void
    {
        $this->httpClient->request(
            HttpRequest::delete(uri: 'v1/schema/'.ucfirst($this->collection))
        );
    }

    /**
     * @throws HttpException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @param Document[] $documents
     * @throws HttpException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $objects = array_map(fn (Document $document): array => [
            'class' => ucfirst($this->collection),
            'id' => (string) $document->getId(),
            'vector' => $document->getEmbedding(),
            'properties' => [
                'content' => $document->getContent(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                'metadata' => $document->metadata,
            ],
        ], $documents);

        $chunks = array_chunk($objects, 100);

        foreach ($chunks as $chunk) {
            $this->httpClient->request(
                HttpRequest::post(
                    uri: 'v1/batch/objects',
                    body: ['objects' => $chunk]
                )
            );
        }

        return $this;
    }

    /**
     * @deprecated Use deleteBy() instead.
     * @throws HttpException
     */
    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    /**
     * @throws HttpException
     */
    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        $conditions = [
            [
                'path' => ['sourceType'],
                'operator' => 'Equal',
                'valueText' => $sourceType,
            ],
        ];

        if ($sourceName !== null) {
            $conditions[] = [
                'path' => ['sourceName'],
                'operator' => 'Equal',
                'valueText' => $sourceName,
            ];
        }

        $where = 1 === count($conditions)
            ? $conditions[0]
            : ['operator' => 'And', 'operands' => $conditions];

        $this->httpClient->request(
            HttpRequest::delete(
                uri: 'v1/batch/objects',
                body: [
                    'match' => [
                        'class' => ucfirst($this->collection),
                        'where' => $where,
                    ],
                ]
            )
        );

        return $this;
    }

    /**
     * @throws HttpException
     */
    public function similaritySearch(array $embedding): iterable
    {
        $vectorString = implode(', ', $embedding);

        $query = sprintf(
            <<<'GQL'
                {
                  Get {
                    %s (
                      nearVector: { vector: [%s] }
                      limit: %d
                    ) {
                      _additional { id vector distance }
                      content
                      sourceType
                      sourceName
                      metadata
                    }
                  }
                }
                GQL,
            ucfirst($this->collection),
            $vectorString,
            $this->topK,
        );

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'v1/graphql',
                body: ['query' => $query]
            )
        )->json();

        $items = $response['data']['Get'][ucfirst($this->collection)] ?? [];

        return array_map(function (array $item): Document {
            $document = new Document($item['content']);
            $document->id = $item['_additional']['id'];
            $document->embedding = $item['_additional']['vector'] ?? [];
            $document->sourceType = $item['sourceType'];
            $document->sourceName = $item['sourceName'];

            $distance = (float) ($item['_additional']['distance'] ?? 0);
            $document->score = 1 - $distance;

            $metadata = $item['metadata'] ?? [];
            if (is_array($metadata)) {
                foreach ($metadata as $key => $value) {
                    if (!in_array($key, ['content', 'sourceType', 'sourceName', 'score', 'embedding', 'id'])) {
                        $document->addMetadata($key, $value);
                    }
                }
            }

            return $document;
        }, $items);
    }

    /**
     * @throws HttpException
     */
    protected function collectionExists(): bool
    {
        $response = $this->httpClient->request(
            HttpRequest::get(uri: 'v1/schema')
        )->json();

        foreach ($response['classes'] ?? [] as $class) {
            if (0 === strcasecmp((string) $class['class'], ucfirst($this->collection))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws HttpException
     */
    protected function createCollection(): void
    {
        $this->httpClient->request(
            HttpRequest::post(
                uri: 'v1/schema',
                body: [
                    'class' => ucfirst($this->collection),
                    'properties' => [
                        ['name' => 'content', 'dataType' => ['text']],
                        ['name' => 'sourceType', 'dataType' => ['text']],
                        ['name' => 'sourceName', 'dataType' => ['text']],
                        ['name' => 'metadata', 'dataType' => ['object']],
                    ],
                ]
            )
        );
    }
}
