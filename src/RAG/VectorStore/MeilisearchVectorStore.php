<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;
use Exception;

use function array_map;
use function in_array;
use function is_null;
use function min;
use function range;
use function sleep;
use function uniqid;
use function array_chunk;

class MeilisearchVectorStore implements VectorStoreInterface
{
    use HasHttpClient;

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
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
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
                        ...$document->metadata,
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
        $filter = $sourceName !== null
            ? "sourceType = {$sourceType} AND sourceName = '{$sourceName}'"
            : "sourceType = {$sourceType}";

        $this->httpClient->request(
            HttpRequest::post(
                uri: "/indexes/{$this->indexUid}/documents/delete",
                body: ['filter' => $filter]
            )
        );

        return $this;
    }

    /**
     * @throws HttpException
     */
    public function similaritySearch(array $embedding): iterable
    {
        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: "/indexes/{$this->indexUid}/search",
                body: [
                    'vector' => $embedding,
                    'limit' => min($this->topK, 20),
                    'retrieveVectors' => true,
                    'showRankingScore' => true,
                    'hybrid' => [
                        'semanticRatio' => 1.0,
                        'embedder' => $this->embedder,
                    ],
                ]
            )
        )->json();

        return array_map(function (array $item): Document {
            $document = new Document($item['content']);
            $document->id = $item['id'] ?? uniqid();
            $document->sourceType = $item['sourceType'] ?? null;
            $document->sourceName = $item['sourceName'] ?? null;
            $document->embedding = $item['_vectors']['default']['embeddings'];
            $document->score = $item['_rankingScore'];

            foreach ($item as $name => $value) {
                if (!in_array($name, ['_vectors', '_rankingScore', 'content', 'sourceType', 'sourceName', 'score', 'embedding', 'id'])) {
                    $document->addMetadata($name, $value);
                }
            }

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

        foreach (range(1, 10) as $i) {
            try {
                $task = $this->httpClient->request(
                    HttpRequest::get('tasks/'.$response['taskUid'])
                )->json();
                if ($task['status'] === 'succeeded') {
                    break;
                }
                sleep(1);
            } catch (Exception) {
                sleep(1);
            }
        }

        $this->httpClient->request(
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
        );

        $this->httpClient->request(
            HttpRequest::put(
                uri: "indexes/{$this->indexUid}/settings/filterable-attributes",
                body: ['sourceType', 'sourceName']
            )
        );
    }
}
