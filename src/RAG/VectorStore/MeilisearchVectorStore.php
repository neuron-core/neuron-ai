<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\RAG\Document;
use Exception;

use function array_map;
use function in_array;
use function is_null;
use function json_decode;
use function min;
use function range;
use function sleep;
use function trim;
use function uniqid;

class MeilisearchVectorStore implements VectorStoreInterface
{
    protected Client $client;

    /**
     * @throws GuzzleException
     */
    public function __construct(
        protected string $indexUid,
        protected string $host = 'http://localhost:7700',
        ?string $key = null,
        protected string $embedder = 'default',
        protected int $topK = 5,
        protected int $dimension = 1024,
    ) {
        $this->client = new Client([
            'base_uri' => trim($host, '/').'/indexes/'.$indexUid.'/',
            'headers' => [
                'Content-Type' => 'application/json',
                ...(is_null($key) ? [] : ['Authorization' => "Bearer {$key}"])
            ]
        ]);

        try {
            $this->client->get('');
        } catch (Exception) {
            $this->createIndex();
        }
    }

    /**
     * @throws GuzzleException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @throws GuzzleException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->client->put('documents', [
            RequestOptions::JSON => array_map(fn (Document $document): array => [
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
                ]
            ], $documents),
        ]);

        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        $this->client->post('documents/delete', [
            RequestOptions::JSON => [
                'filter' => "sourceType = {$sourceType} AND sourceName = '{$sourceName}'",
            ]
        ]);

        return $this;
    }

    /**
     * @throws GuzzleException
     */
    public function similaritySearch(array $embedding): iterable
    {
        $response = $this->client->post('search', [
            RequestOptions::JSON => [
                'vector' => $embedding,
                'limit' => min($this->topK, 20),
                'retrieveVectors' => true,
                'showRankingScore' => true,
                'hybrid' => [
                    'semanticRatio' => 1.0,
                    'embedder' => $this->embedder,
                ],
            ]
        ])->getBody()->getContents();

        $response = json_decode($response, true);

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
     * @throws GuzzleException
     */
    protected function createIndex(): void
    {
        $response = $this->client->post(trim($this->host, '/').'/indexes', [
            RequestOptions::JSON => [
                'uid' => $this->indexUid,
                'primaryKey' => 'id',
            ]
        ])->getBody()->getContents();

        $response = json_decode($response, true);

        foreach (range(1, 10) as $i) {
            try {
                $task = $this->client->get(trim($this->host, '/').'/tasks/'.$response['taskUid'])->getBody()->getContents();
                $task = json_decode($task, true);
                if ($task['status'] === 'succeeded') {
                    break;
                }
                sleep(1);
            } catch (Exception) {
                sleep(1);
            }
        }

        $this->client->patch(trim($this->host, '/')."/indexes/{$this->indexUid}/settings/embedders", [
            RequestOptions::JSON => [
                $this->embedder => [
                    'dimensions' => $this->dimension,
                    'source' => 'userProvided',
                    'binaryQuantized' => false
                ]
            ]
        ]);

        $this->client->put(trim($this->host, '/')."/indexes/{$this->indexUid}/settings/filterable-attributes", [
            RequestOptions::JSON => ['sourceType', 'sourceName']
        ]);
    }
}
