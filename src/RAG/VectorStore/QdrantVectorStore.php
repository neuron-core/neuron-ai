<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\RAG\Document;

class QdrantVectorStore implements VectorStoreInterface
{
    protected Client $client;

    public function __construct(
        protected string $collectionUrl, // like http://localhost:6333/collections/neuron-ai/
        protected ?string $key = null,
        protected int $topK = 4,
    ) {
        $this->client = new Client([
            'base_uri' => \trim($this->collectionUrl, '/').'/',
            'headers' => [
                'Content-Type' => 'application/json',
                ...(!\is_null($this->key) && $this->key !== '' ? ['api-key' => $this->key] : [])
            ],
        ]);
    }

    public function initialize(int $size, string $distance, bool $override = false): void
    {
        $response = $this->client->get('exists')->getBody()->getContents();
        $response = \json_decode($response, true);

        if ($response['result']['exists']) {
            if ($override) {
                $this->destroy();
            } else {
                return;
            }
        }

        $this->client->put('', [
            RequestOptions::JSON => [
                'vectors' => [
                    'size' => $size,
                    'distance' => $distance,
                ],
            ],
        ]);
    }

    public function destroy(): void
    {
        $this->client->delete('');
    }

    /**
     * @throws GuzzleException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * Bulk save documents.
     *
     * @param Document[] $documents
     * @throws GuzzleException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $points = \array_map(fn (Document $document): array => [
            'id' => $document->getId(),
            'payload' => [
                'content' => $document->getContent(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                ...$document->metadata,
            ],
            'vector' => $document->getEmbedding(),
        ], $documents);

        $this->client->put('points', [
            RequestOptions::JSON => ['points' => $points]
        ]);

        return $this;
    }

    /**
     * @throws GuzzleException
     */
    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        $this->client->post('points/delete', [
            RequestOptions::JSON => [
                'wait' => true,
                'filter' => [
                    'must' => [
                        [
                            'key' => 'sourceType',
                            'match' => [
                                'value' => $sourceType,
                            ]
                        ],
                        [
                            'key' => 'sourceName',
                            'match' => [
                                'value' => $sourceName,
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        return $this;
    }

    public function similaritySearch(array $embedding): iterable
    {
        $response = $this->client->post('points/search', [
            RequestOptions::JSON => [
                'vector' => $embedding,
                'limit' => $this->topK,
                'with_payload' => true,
                'with_vector' => true,
            ]
        ])->getBody()->getContents();

        $response = \json_decode($response, true);

        return \array_map(function (array $item): Document {
            $document = new Document($item['payload']['content']);
            $document->id = $item['id'];
            $document->embedding = $item['vector'];
            $document->sourceType = $item['payload']['sourceType'];
            $document->sourceName = $item['payload']['sourceName'];
            $document->score = $item['score'];

            foreach ($item['payload'] as $name => $value) {
                if (!\in_array($name, ['content', 'sourceType', 'sourceName', 'score', 'embedding', 'id'])) {
                    $document->addMetadata($name, $value);
                }
            }

            return $document;
        }, $response['result']);
    }
}
