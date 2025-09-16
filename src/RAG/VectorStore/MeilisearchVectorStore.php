<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;

class MeilisearchVectorStore implements VectorStoreInterface
{
    protected Client $client;

    public function __construct(
        string $indexUid,
        string $host = 'http://localhost:7700',
        ?string $key = null,
        protected string $embedder = 'default',
        protected int $topK = 5,
    ) {
        $this->client = new Client([
            'base_uri' => \trim($host, '/').'/indexes/'.$indexUid.'/',
            'headers' => [
                'Content-Type' => 'application/json',
                ...(\is_null($key) ? [] : ['Authorization' => "Bearer {$key}"])
            ]
        ]);

        try {
            $this->client->get('');
        } catch (\Exception) {
            throw new VectorStoreException("Index {$indexUid} doesn't exists. Remember to attach a custom embedder to the index in order to process vectors.");
        }
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->client->put('documents', [
            RequestOptions::JSON => \array_map(fn (Document $document): array => [
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
                'filter' => "sourceType = {$sourceType} AND sourceName = {$sourceName}",
            ]
        ]);

        return $this;
    }

    public function similaritySearch(array $embedding): iterable
    {
        $response = $this->client->post('search', [
            RequestOptions::JSON => [
                'vector' => $embedding,
                'limit' => \min($this->topK, 20),
                'retrieveVectors' => true,
                'showRankingScore' => true,
                'hybrid' => [
                    'semanticRatio' => 1.0,
                    'embedder' => $this->embedder,
                ],
            ]
        ])->getBody()->getContents();

        $response = \json_decode($response, true);

        return \array_map(function (array $item): Document {
            $document = new Document($item['content']);
            $document->id = $item['id'] ?? \uniqid();
            $document->sourceType = $item['sourceType'] ?? null;
            $document->sourceName = $item['sourceName'] ?? null;
            $document->embedding = $item['_vectors']['default']['embeddings'];
            $document->score = $item['_rankingScore'];

            foreach ($item as $name => $value) {
                if (!\in_array($name, ['_vectors', '_rankingScore', 'content', 'sourceType', 'sourceName', 'score', 'embedding', 'id'])) {
                    $document->addMetadata($name, $value);
                }
            }

            return $document;
        }, $response['hits']);
    }
}
