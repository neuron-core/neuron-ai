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
use function implode;
use function json_encode;
use function max;

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
     * @throws JsonException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        $this->validateDocument($document);

        $this->checkIndexStatus($document);

        $this->client->collections[$this->collection]->documents->create([
            'id' => (string) $document->getId(), // Unique ID is required
            'content' => $document->getContent(),
            'embedding' => $document->getEmbedding(),
            'sourceType' => $document->getSourceType(),
            'sourceName' => $document->getSourceName(),
            ...MetadataMapper::toStorage($document, $this->schema),
        ]);

        return $this;
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
     * Bulk save.
     *
     * @param Document[] $documents
     * @throws Exception
     * @throws JsonException
     * @throws TypesenseClientError|\NeuronAI\Exceptions\VectorStoreException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        $this->validateDocuments($documents);

        $this->checkIndexStatus($documents[0]);

        $lines = [];
        foreach ($documents as $document) {
            $lines[] = json_encode([
                'id' => (string) $document->getId(), // Unique ID is required
                'embedding' => $document->getEmbedding(),
                'content' => $document->getContent(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                ...MetadataMapper::toStorage($document, $this->schema),
            ]);
        }

        $chunks = array_chunk($lines, 100);

        foreach ($chunks as $chunk) {
            $ndjson = implode("\n", $chunk);
            $this->client->collections[$this->collection]->documents->import($ndjson);
        }

        return $this;
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
            $document->setSourceType($item['sourceType'])
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
