<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use Http\Client\Exception;
use JsonException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentFieldType;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\Compilers\TypesenseFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\TypesenseClientError;

use function array_chunk;
use function array_key_exists;
use function array_map;
use function count;
use function explode;
use function implode;
use function json_decode;
use function json_encode;
use function max;

use const JSON_THROW_ON_ERROR;

class TypesenseVectorStore implements VectorStoreInterface
{
    use HasDocumentSchema;

    public function __construct(
        protected Client $client,
        protected string $collection,
        protected int $vectorDimension,
        protected string $topK = '4',
        ?DocumentSchema $schema = null,
    ) {
        $this->initializeSchema($schema);
    }

    /**
     * @throws Exception
     * @throws TypesenseClientError
     * @throws \Exception
     */
    public function checkIndexStatus(Document $document): void
    {
        try {
            $this->client->collections[$this->collection]->retrieve();
            $this->checkVectorDimension(count($document->getEmbedding()));
            return;
        } catch (ObjectNotFound) {
            $fields = [
                [
                    'name' => 'content',
                    'type' => 'string',
                ],
                [
                    'name' => 'sourceType',
                    'type' => 'string',
                    'facet' => true,
                ],
                [
                    'name' => 'sourceName',
                    'type' => 'string',
                    'facet' => true,
                ],
                [
                    'name' => 'embedding',
                    'type' => 'float[]',
                    'num_dim' => $this->vectorDimension,
                ],
                [
                    'name' => MetadataMapper::PAYLOAD_FIELD,
                    'type' => 'string',
                    'optional' => true,
                    'index' => false,
                ],
            ];

            foreach ($this->schema->fields() as $field) {
                $fields[] = [
                    'name' => $field->getName(),
                    'type' => match ($field->getType()) {
                        DocumentFieldType::String => 'string',
                        DocumentFieldType::Integer => 'int64',
                        DocumentFieldType::Float => 'float',
                        DocumentFieldType::Boolean => 'bool',
                        DocumentFieldType::StringArray => 'string[]',
                        DocumentFieldType::IntegerArray => 'int64[]',
                        DocumentFieldType::FloatArray => 'float[]',
                        DocumentFieldType::BooleanArray => 'bool[]',
                    },
                    'optional' => !$field->isRequired(),
                    'facet' => $field->isFilterable(),
                ];
            }

            $this->client->collections->create([
                'name' => $this->collection,
                'fields' => $fields,
            ]);
        }
    }

    /**
     * @throws Exception
     * @throws TypesenseClientError
     * @throws VectorStoreException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @throws Exception
     * @throws TypesenseClientError
     * @throws DocumentSchemaException
     */
    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);
        $this->client->collections[$this->collection]->documents->delete([
            "filter_by" => (new TypesenseFilterCompiler())->compile($filters),
        ]);

        return $this;
    }

    /**
     * Bulk save. A document whose ID is already stored replaces it.
     *
     * @param Document[] $documents
     * @throws Exception
     * @throws TypesenseClientError
     * @throws VectorStoreException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        $this->validateDocuments($documents);

        // Every line is encoded before any request: a document that cannot be stored refuses the whole batch
        $lines = array_map($this->encodeLine(...), $documents);

        $this->checkIndexStatus($documents[0]);

        $documentChunks = array_chunk($documents, 100);

        foreach (array_chunk($lines, 100) as $index => $chunk) {
            $answer = $this->client->collections[$this->collection]->documents->import(
                implode("\n", $chunk),
                ['action' => 'upsert']
            );
            $this->assertImported($documentChunks[$index], (string) $answer);
        }

        return $this;
    }

    /**
     * @throws VectorStoreException
     */
    protected function encodeLine(Document $document): string
    {
        try {
            return json_encode([
                'id' => (string) $document->getId(), // Unique ID is required
                'embedding' => $document->getEmbedding(),
                'content' => $document->getContent(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                ...MetadataMapper::toStorage($document, $this->schema),
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new VectorStoreException("Document {$document->getId()} is not JSON serializable: {$exception->getMessage()}", $exception->getCode(), $exception);
        }
    }

    /**
     * Typesense answers an import with HTTP 200 and one result line per document, in order.
     *
     * @param Document[] $documents
     * @throws VectorStoreException
     */
    protected function assertImported(array $documents, string $answer): void
    {
        $rejections = [];
        foreach (explode("\n", $answer) as $position => $line) {
            $result = json_decode($line, true);
            if (($result['success'] ?? false) !== true) {
                $rejections[] = "[{$documents[$position]->getId()}] ".($result['error'] ?? $line);
            }
        }

        if ($rejections !== []) {
            throw new VectorStoreException(
                'Typesense rejected '.count($rejections).' of '.count($documents).' documents: '.implode('; ', $rejections)
            );
        }
    }

    /**
     * @throws Exception
     * @throws TypesenseClientError
     * @throws VectorStoreException
     * @throws DocumentSchemaException
     */
    public function search(SearchRequest $request): array
    {
        if ($request->filters instanceof FilterExpression) {
            $this->validateFilters($request->filters);
        }

        $topK = $request->topK ?? (int) $this->topK;

        $params = [
            'collection' => $this->collection,
            'q' => '*',
            'vector_query' => 'embedding:(' . json_encode($request->embedding) . ')',
            'exclude_fields' => 'embedding',
            'per_page' => $topK,
            'num_candidates' => max(50, $topK * 4),
        ];

        if ($request->filters instanceof FilterExpression) {
            $params['filter_by'] = (new TypesenseFilterCompiler())->compile($request->filters);
        }

        $searchRequests = ['searches' => [$params]];

        $response = $this->client->multiSearch->perform($searchRequests);
        return array_map(function (array $hit): Document {
            $item = $hit['document'];
            $document = new Document($item['content']);
            $document->setId($item['id'])
                ->setSourceType($item['sourceType'])
                ->setSourceName($item['sourceName'])
                ->setScore(VectorSimilarity::similarityFromDistance($hit['vector_distance']));

            MetadataMapper::hydrate($document, $item);

            return $document;
        }, $response['results'][0]['hits']);
    }

    /**
     * @throws Exception
     * @throws TypesenseClientError
     */
    private function checkVectorDimension(int $dimension): void
    {
        $schema = $this->client->collections[$this->collection]->retrieve();

        $embeddingField = null;

        foreach ($schema['fields'] as $field) {
            if ($field['name'] === 'embedding') {
                $embeddingField = $field;
                break;
            }
        }

        if (
            array_key_exists('num_dim', $embeddingField)
            && $embeddingField['num_dim'] === $dimension
        ) {
            return;
        }

        throw new \Exception(
            "Vector embeddings dimension {$dimension} must be the same as the initial setup {$this->vectorDimension} - ".
            json_encode($embeddingField)
        );
    }
}
