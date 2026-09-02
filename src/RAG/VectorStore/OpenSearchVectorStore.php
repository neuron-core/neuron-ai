<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use Exception;
use JsonException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentFieldType;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Compilers\OpenSearchFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use OpenSearch\Client;

use function array_key_exists;
use function array_map;
use function count;
use function max;

class OpenSearchVectorStore implements VectorStoreInterface
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
        $indexExists = $this->client->indices()->exists(['index' => $this->index]);

        if ($indexExists) {
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
            'embedding' => [
                'type' => 'knn_vector',
                'dimension' => count($document->getEmbedding()),
                'index' => true,
                'method' => [
                    'name' => 'hnsw',
                    'engine' => 'lucene',
                    'space_type' => 'cosinesimil',
                    'parameters' => [
                        'encoder' => [
                            'name' => 'sq',
                            'parameters' => [
                                'bits' => 7,
                            ],
                        ],
                    ],
                ],
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
                'settings' => [
                    'index' => [
                        'knn' => true,
                    ],
                ],
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
     * @throws VectorStoreException
     * @throws JsonException
     * @throws Exception
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        $this->validateDocuments($documents);

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
                ...MetadataMapper::toStorage($document, $this->schema),
            ];
        }
        $this->client->bulk($params);
        $this->client->indices()->refresh(['index' => $this->index]);
        return $this;
    }

    /**
     * @throws DocumentSchemaException
     */
    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);
        $this->client->deleteByQuery([
            'index' => $this->index,
            'body' => [
                'query' => (new OpenSearchFilterCompiler())->compile($filters),
            ],
        ]);
        $this->client->indices()->refresh(['index' => $this->index]);
        return $this;
    }

    /**
     * @return Document[]
     * @throws DocumentSchemaException|VectorStoreException
     */
    public function search(SearchRequest $request): iterable
    {
        if ($request->filters instanceof FilterExpression) {
            $this->validateFilters($request->filters);
        }

        $topK = $request->topK ?? $this->topK;

        $searchParams = [
            'index' => $this->index,
            'body' => [
                'query' => [
                    'knn' => [
                        'embedding' => [
                            'vector' => $request->embedding,
                            'k' => max(50, $topK * 4),
                        ],
                    ],
                ],
                'sort' => [
                    '_score' => [
                        'order' => 'desc',
                    ],
                ],
            ],
        ];

        if ($request->filters instanceof FilterExpression) {
            $searchParams['body']['query']['knn']['embedding']['filter'] = (new OpenSearchFilterCompiler())->compile($request->filters);
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
            && $mappings['embedding']['mapping']['embedding']['dimension'] === $dimension
        ) {
            return;
        }

        $this->client->indices()->putMapping([
            'index' => $this->index,
            'body' => [
                'properties' => [
                    'embedding' => [
                        'type' => 'knn_vector',
                        'dimension' => $dimension,
                        'index' => true,
                        'method' => [
                            'name' => 'hnsw',
                            'engine' => 'lucene',
                            'space_type' => 'cosinesimil',
                            'parameters' => [
                                'encoder' => [
                                    'name' => 'sq',
                                    'parameters' => [
                                        'bits' => 7,
                                    ],
                                ],
                            ],

                        ],
                    ],
                ],
            ],
        ]);

        $this->vectorDimSet = true;
    }
}
