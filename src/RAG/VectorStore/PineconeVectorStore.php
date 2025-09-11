<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\RAG\Document;

class PineconeVectorStore implements VectorStoreInterface
{
    protected Client $client;

    /**
     * Metadata filters.
     *
     * https://docs.pinecone.io/reference/api/2025-04/data-plane/query#body-filter
     */
    protected array $filters = [];

    public function __construct(
        string $key,
        protected string $indexUrl,
        protected int $topK = 4,
        string $version = '2025-04',
        protected string $namespace = '__default__'
    ) {
        $this->client = new Client([
            'base_uri' => \trim($this->indexUrl, '/').'/',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Api-Key' => $key,
                'X-Pinecone-API-Version' => $version,
            ]
        ]);
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->client->post("vectors/upsert", [
            RequestOptions::JSON => [
                'namespace' => $this->namespace,
                'vectors' => \array_map(fn (Document $document): array => [
                    'id' => (string) $document->getId(),
                    'values' => $document->getEmbedding(),
                    'metadata' => [
                        'content' => $document->getContent(),
                        'sourceType' => $document->getSourceType(),
                        'sourceName' => $document->getSourceName(),
                        ...$document->metadata,
                    ],
                ], $documents)
            ]
        ]);

        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        $this->client->post("vectors/delete", [
            RequestOptions::JSON => [
                'namespace' => $this->namespace,
                'filter' => [
                    'sourceType' => ['$eq' => $sourceType],
                    'sourceName' => ['$eq' => $sourceName],
                ]
            ]
        ]);

        return $this;
    }

    public function similaritySearch(array $embedding): iterable
    {
        $result = $this->client->post("query", [
            RequestOptions::JSON => [
                'namespace' => $this->namespace,
                'includeMetadata' => true,
                'includeValues' => true,
                'vector' => $embedding,
                'topK' => $this->topK,
                'filter' => $this->filters, // Hybrid search
            ]
        ])->getBody()->getContents();

        $result = \json_decode($result, true);

        return \array_map(function (array $item): Document {
            $document = new Document();
            $document->id = $item['id'];
            $document->embedding = $item['values'];
            $document->content = $item['metadata']['content'];
            $document->sourceType = $item['metadata']['sourceType'];
            $document->sourceName = $item['metadata']['sourceName'];
            $document->score = $item['score'];

            foreach ($item['metadata'] as $name => $value) {
                if (!\in_array($name, ['content', 'sourceType', 'sourceName'])) {
                    $document->addMetadata($name, $value);
                }
            }

            return $document;
        }, $result['matches']);
    }

    public function withFilters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }
}
