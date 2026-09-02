<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch;
use JsonException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentFieldType;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Compilers\ElasticsearchFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;

use function array_chunk;
use function array_key_exists;
use function array_map;
use function count;
use function max;

class ElasticsearchVectorStore implements VectorStoreInterface
{
    use HasDocumentSchema;

    protected bool $vectorDimSet = false;

    public function __construct(
        protected Client $client,
        protected string $index,
        protected int $topK = 4,
        ?DocumentSchema $schema = null,
    ) {
        $this->initializeSchema($schema);
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
            ],
            MetadataMapper::PAYLOAD_FIELD => [
                'type' => 'text',
                'index' => false,
            ],
        ];

        foreach ($this->schema->fields() as $field) {
            $properties[$field->getName()] = [
                'type' => match ($field->getType()) {
                    DocumentFieldType::String, DocumentFieldType::StringArray => 'keyword',
                    DocumentFieldType::Integer, DocumentFieldType::IntegerArray => 'long',
                    DocumentFieldType::Float, DocumentFieldType::FloatArray => 'double',
                    DocumentFieldType::Boolean, DocumentFieldType::BooleanArray => 'boolean',
                },
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
     * @throws ClientResponseException
     * @throws VectorStoreException
     * @throws ServerResponseException
     * @throws DocumentSchemaException
     * @throws JsonException
     * @throws MissingParameterException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        $this->validateDocument($document);

        $this->checkIndexStatus($document);

        $this->client->index([
            'index' => $this->index,
            'body' => [
                'embedding' => $document->getEmbedding(),
                'content' => $document->getContent(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                ...MetadataMapper::toStorage($document, $this->schema),
            ],
        ]);

        $this->client->indices()->refresh(['index' => $this->index]);

        return $this;
    }

    /**
     * @param Document[] $documents
     * @throws ClientResponseException
     * @throws JsonException
     * @throws ServerResponseException
     * @throws VectorStoreException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        $this->validateDocuments($documents);

        $this->checkIndexStatus($documents[0]);

        $chunks = array_chunk($documents, 100);

        /*
         * Generate a bulk payload
         */
        $params = ['body' => []];
        foreach ($chunks as $chunk) {
            foreach ($chunk as $document) {
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
                    ...MetadataMapper::toStorage($document, $this->schema),
                ];
            }
            $this->client->bulk($params);
            $this->client->indices()->refresh(['index' => $this->index]);
        }

        return $this;
    }

    /**
     * @throws ClientResponseException
     * @throws ServerResponseException
     * @throws MissingParameterException
     * @throws DocumentSchemaException
     */
    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);
        $this->client->deleteByQuery([
            'index' => $this->index,
            'body' => [
                'query' => (new ElasticsearchFilterCompiler())->compile($filters),
            ],
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
     * @throws DocumentSchemaException|VectorStoreException
     */
    public function search(SearchRequest $request): array
    {
        if ($request->filters instanceof FilterExpression) {
            $this->validateFilters($request->filters);
        }

        $topK = $request->topK ?? $this->topK;

        $searchParams = [
            'index' => $this->index,
            'body' => [
                'knn' => [
                    'field' => 'embedding',
                    'query_vector' => $request->embedding,
                    'k' => $topK,
                    'num_candidates' => max(50, $topK * 4),
                ],
                'sort' => [
                    '_score' => [
                        'order' => 'desc',
                    ],
                ],
            ],
        ];

        if ($request->filters instanceof FilterExpression) {
            $searchParams['body']['knn']['filter'] = (new ElasticsearchFilterCompiler())->compile($request->filters);
        }

        $response = $this->client->search($searchParams);

        return array_map(function (array $item): Document {
            $document = new Document($item['_source']['content']);
            $document->setSourceType($item['_source']['sourceType'])
                ->setSourceName($item['_source']['sourceName'])
                ->setScore($item['_score']);

            MetadataMapper::hydrate($document, $item['_source']);

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
}
