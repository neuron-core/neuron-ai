<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\HttpClient\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentFieldType;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Compilers\WeaviateFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;

use function array_chunk;
use function array_map;
use function implode;
use function in_array;
use function is_null;
use function sprintf;
use function ucfirst;
use function is_array;
use function json_decode;
use function json_encode;
use function strcasecmp;
use function array_key_exists;

class WeaviateVectorStore implements VectorStoreInterface
{
    use HasHttpClient;
    use HasDocumentSchema;

    /**
     * @throws HttpException
     */
    public function __construct(
        protected string $collection,
        protected string $host = 'http://localhost:8080',
        protected ?string $key = null,
        protected int $topK = 5,
        ?HttpClientInterface $httpClient = null,
        ?DocumentSchema $schema = null,
    ) {
        $this->initializeSchema($schema);
        $this->httpClient = ($httpClient ?? new CurlHttpClient())
            ->withBaseUri($host)
            ->withHeaders([
                'Content-Type' => 'application/json',
                ...(!is_null($this->key) && $this->key !== '' ? ['Authorization' => 'Bearer '.$this->key] : []),
            ]);

        $this->initialize();
    }

    /**
     * @throws HttpException
     */
    protected function initialize(): void
    {
        if ($this->collectionExists()) {
            return;
        }

        $this->createCollection();
    }

    /**
     * @throws HttpException
     */
    public function destroy(): void
    {
        $this->httpClient->request(
            HttpRequest::delete(uri: 'v1/schema/'.ucfirst($this->collection))
        );
    }

    /**
     * @throws HttpException
     * @throws VectorStoreException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @param Document[] $documents
     * @throws HttpException
     * @throws VectorStoreException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->validateDocuments($documents);
        $objects = array_map(fn (Document $document): array => [
            'class' => ucfirst($this->collection),
            'id' => (string) $document->getId(),
            'vector' => $document->getEmbedding(),
            'properties' => [
                'content' => $document->getContent(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                'metadata' => json_encode($document->getMetadata()),
                ...$this->declaredMetadata($document),
            ],
        ], $documents);

        $chunks = array_chunk($objects, 100);

        foreach ($chunks as $chunk) {
            $this->httpClient->request(
                HttpRequest::post(
                    uri: 'v1/batch/objects',
                    body: ['objects' => $chunk]
                )
            );
        }

        return $this;
    }

    /**
     * @throws HttpException
     * @throws DocumentSchemaException
     */
    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);
        $this->httpClient->request(
            HttpRequest::delete(
                uri: 'v1/batch/objects',
                body: [
                    'match' => [
                        'class' => ucfirst($this->collection),
                        'where' => (new WeaviateFilterCompiler())->compile($filters),
                    ],
                ]
            )
        );

        return $this;
    }

    /**
     * @throws HttpException
     * @throws DocumentSchemaException
     */
    public function search(SearchRequest $request): iterable
    {
        if ($request->filters instanceof FilterExpression) {
            $this->validateFilters($request->filters);
        }

        $vectorString = implode(', ', $request->embedding);

        $where = $request->filters instanceof FilterExpression
            ? 'where: ' . (new WeaviateFilterCompiler())->compileGraphQL($request->filters)
            : '';

        $query = sprintf(
            <<<'GQL'
                {
                  Get {
                    %s (
                      nearVector: { vector: [%s] }
                      limit: %d
                      %s
                    ) {
                      _additional { id vector distance }
                      content
                      sourceType
                      sourceName
                      metadata
                    }
                  }
                }
                GQL,
            ucfirst($this->collection),
            $vectorString,
            $request->topK ?? $this->topK,
            $where,
        );

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'v1/graphql',
                body: ['query' => $query]
            )
        )->json();

        $items = $response['data']['Get'][ucfirst($this->collection)] ?? [];

        return array_map(function (array $item): Document {
            $document = new Document($item['content']);
            $document->setId($item['_additional']['id'])
                ->setSourceType($item['sourceType'])
                ->setSourceName($item['sourceName']);
            if (($item['_additional']['vector'] ?? []) !== []) {
                $document->setEmbedding($item['_additional']['vector']);
            }

            $distance = (float) ($item['_additional']['distance'] ?? 0);
            $document->setScore(1 - $distance);

            $metadata = json_decode($item['metadata'] ?? '{}', true);
            if (is_array($metadata)) {
                foreach ($metadata as $key => $value) {
                    if (!in_array($key, ['content', 'sourceType', 'sourceName', 'score', 'embedding', 'id'])) {
                        $document->addMetadata($key, $value);
                    }
                }
            }

            return $document;
        }, $items);
    }

    /**
     * @throws HttpException
     */
    protected function collectionExists(): bool
    {
        $response = $this->httpClient->request(
            HttpRequest::get(uri: 'v1/schema')
        )->json();

        foreach ($response['classes'] ?? [] as $class) {
            if (0 === strcasecmp((string) $class['class'], ucfirst($this->collection))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws HttpException
     */
    protected function createCollection(): void
    {
        $properties = [
            ['name' => 'content', 'dataType' => ['text']],
            ['name' => 'sourceType', 'dataType' => ['text']],
            ['name' => 'sourceName', 'dataType' => ['text']],
            ['name' => 'metadata', 'dataType' => ['text']],
        ];

        foreach ($this->schema->fields() as $field) {
            $properties[] = [
                'name' => $field->getName(),
                'dataType' => [match ($field->getType()) {
                    DocumentFieldType::String => 'text',
                    DocumentFieldType::Integer => 'int',
                    DocumentFieldType::Float => 'number',
                    DocumentFieldType::Boolean => 'boolean',
                    DocumentFieldType::StringArray => 'text[]',
                    DocumentFieldType::IntegerArray => 'int[]',
                    DocumentFieldType::FloatArray => 'number[]',
                    DocumentFieldType::BooleanArray => 'boolean[]',
                }],
            ];
        }

        $this->httpClient->request(
            HttpRequest::post(
                uri: 'v1/schema',
                body: [
                    'class' => ucfirst($this->collection),
                    'properties' => $properties,
                ]
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function declaredMetadata(Document $document): array
    {
        $metadata = $document->getMetadata();
        $declared = [];

        foreach ($this->schema->fields() as $field) {
            $name = $field->getName();
            if (array_key_exists($name, $metadata) && $metadata[$name] !== null) {
                $declared[$name] = $metadata[$name];
            }
        }

        return $declared;
    }
}
