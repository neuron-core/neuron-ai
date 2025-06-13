<?php

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
     *
     * @var array
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
            'base_uri' => trim($this->indexUrl, '/').'/',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Api-Key' => $key,
                'X-Pinecone-API-Version' => $version,
            ]
        ]);
    }

    public function addDocument(Document $document): void
    {
        $this->addDocuments([$document]);
    }

    public function addDocuments(array $documents): void
    {
        $this->client->post("vectors/upsert", [
            RequestOptions::JSON => [
                'namespace' => $this->namespace,
                'vectors' => \array_map(fn (Document $document) => [
                    'id' => $document->id ?? \uniqid(),
                    'values' => $document->embedding,
                    'metadata' => [
                        'content' => $document->content,
                        'sourceType' => $document->sourceType,
                        'sourceName' => $document->sourceName,
                    ],
                ], $documents)
            ]
        ]);
    }

    public function similaritySearch(array $embedding): iterable
    {
        $result = $this->client->post("query", [
            RequestOptions::JSON => [
                'namespace' => $this->namespace,
                'includeMetadata' => true,
                'vector' => $embedding,
                'topK' => $this->topK,
                'filters' => $this->filters,
            ]
        ])->getBody()->getContents();

        $result = \json_decode($result, true);

        return \array_map(function (array $item) {
            $document = new Document();
            $document->id = $item['id'];
            $document->embedding = $item['values'];
            $document->content = $item['metadata']['content'];
            $document->sourceType = $item['metadata']['sourceType'];
            $document->sourceName = $item['metadata']['sourceName'];
            $document->score = $item['score'];
            return $document;
        }, $result['matches']);
    }

    public function withFilters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }
}
