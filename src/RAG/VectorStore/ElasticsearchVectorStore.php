<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use NeuronAI\RAG\Document;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Exception;

use function array_key_exists;
use function array_keys;
use function array_map;
use function count;
use function in_array;
use function max;

class ElasticsearchVectorStore implements VectorStoreInterface
{
    protected bool $vectorDimSet = false;

    protected array $filters = [];

    public function __construct(
        protected Client $client,
        protected string $index,
        protected int $topK = 4,
    ) {
    }

    protected function checkIndexStatus(Document $document): void
    {
        /** @var Elasticsearch $existResponse */
        $existResponse = $this->client->indices()->exists(['index' => $this->index]);
        $existStatusCode = $existResponse->getStatusCode();

        if ($existStatusCode === 200) {
            // Map vector embeddings dimension on the fly adding a document.
            $this->mapVectorDimension(count($document->getEmbedding()));
            return;
        }

        $properties = [
            'content' => [
                'type' => 'text',
            ],
            'sourceType' => [
                'type' => 'keyword',
            ],
            'sourceName' => [
                'type' => 'keyword',
            ]
        ];

        // Map metadata
        foreach (array_keys($document->metadata) as $name) {
            $properties[$name] = [
                'type' => 'keyword',
            ];
        }

        $this->client->indices()->create([
            'index' => $this->index,
            'body' => [
                'mappings' => [
                    'properties' => $properties,
                ],
            ],
        ]);
    }

    /**
     * @throws Exception
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        if ($document->embedding === []) {
            throw new Exception('Document embedding must be set before adding a document');
        }

        $this->checkIndexStatus($document);

        $this->client->index([
            'index' => $this->index,
            'body' => [
                'embedding' => $document->getEmbedding(),
                'content' => $document->getContent(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                ...$document->metadata,
            ],
        ]);

        $this->client->indices()->refresh(['index' => $this->index]);

        return $this;
    }

    /**
     * @param  Document[]  $documents
     *
     * @throws Exception
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        if (empty($documents[0]->getEmbedding())) {
            throw new Exception('Document embedding must be set before adding a document');
        }

        $this->checkIndexStatus($documents[0]);

        /*
         * Generate a bulk payload
         */
        $params = ['body' => []];
        foreach ($documents as $document) {
            $params['body'][] = [
                'index' => [
                    '_index' => $this->index,
                ],
            ];
            $params['body'][] = [
                'embedding' => $document->getEmbedding(),
                'content' => $document->getContent(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                ...$document->metadata,
            ];
        }
        $this->client->bulk($params);
        $this->client->indices()->refresh(['index' => $this->index]);
        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        $this->client->deleteByQuery([
            'index' => $this->index,
            'q' => "sourceType:{$sourceType} AND sourceName:{$sourceName}",
            'body' => []
        ]);
        $this->client->indices()->refresh(['index' => $this->index]);
        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * num_candidates are used to tune approximate kNN for speed or accuracy (see : https://www.elastic.co/guide/en/elasticsearch/reference/current/knn-search.html#tune-approximate-knn-for-speed-accuracy)
     * @return Document[]
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    public function similaritySearch(array $embedding): array
    {
        $searchParams = [
            'index' => $this->index,
            'body' => [
                'knn' => [
                    'field' => 'embedding',
                    'query_vector' => $embedding,
                    'k' => $this->topK,
                    'num_candidates' => max(50, $this->topK * 4),
                ],
                'sort' => [
                    '_score' => [
                        'order' => 'desc',
                    ],
                ],
            ],
        ];

        // Hybrid search
        if ($this->filters !== []) {
            $searchParams['body']['knn']['filter'] = $this->filters;
        }

        $response = $this->client->search($searchParams);

        return array_map(function (array $item): Document {
            $document = new Document($item['_source']['content']);
            //$document->embedding = $item['_source']['embedding']; // avoid carrying large data
            $document->sourceType = $item['_source']['sourceType'];
            $document->sourceName = $item['_source']['sourceName'];
            $document->score = $item['_score'];

            foreach ($item['_source'] as $name => $value) {
                if (!in_array($name, ['content', 'sourceType', 'sourceName', 'score', 'embedding', 'id'])) {
                    $document->addMetadata($name, $value);
                }
            }

            return $document;
        }, $response['hits']['hits']);
    }

    /**
     * Map vector embeddings dimension on the fly.
     */
    private function mapVectorDimension(int $dimension): void
    {
        if ($this->vectorDimSet) {
            return;
        }

        $response = $this->client->indices()->getFieldMapping([
            'index' => $this->index,
            'fields' => 'embedding',
        ]);

        $mappings = $response[$this->index]['mappings'];
        if (
            array_key_exists('embedding', $mappings)
            && $mappings['embedding']['mapping']['embedding']['dims'] === $dimension
        ) {
            return;
        }

        $this->client->indices()->putMapping([
            'index' => $this->index,
            'body' => [
                'properties' => [
                    'embedding' => [
                        'type' => 'dense_vector',
                        //'element_type' => 'float', // it's float by default
                        'dims' => $dimension,
                        'index' => true,
                        'similarity' => 'cosine',
                    ],
                ],
            ],
        ]);

        $this->vectorDimSet = true;
    }

    public function withFilters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }
}
