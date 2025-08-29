<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use Http\Client\Exception;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\TypesenseClientError;

class TypesenseVectorStore implements VectorStoreInterface
{
    public function __construct(
        protected Client $client,
        protected string $collection,
        protected int $vectorDimension,
        protected string $topK = '4',
    ) {
    }

    /**
     * @throws Exception
     * @throws TypesenseClientError
     */
    public function checkIndexStatus(Document $document): void
    {
        try {
            $this->client->collections[$this->collection]->retrieve();
            $this->checkVectorDimension(\count($document->getEmbedding()));
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
            ];

            // Map custom fields
            foreach ($document->metadata as $name => $value) {
                $fields[] = [
                    'name' => $name,
                    'type' => \gettype($value),
                    'facet' => true,
                ];
            }

            $this->client->collections->create([
                'name' => $this->collection,
                'fields' => $fields,
            ]);
        }
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        if ($document->getEmbedding() === []) {
            throw new \Exception('document embedding must be set before adding a document');
        }

        $this->checkIndexStatus($document);

        $this->client->collections[$this->collection]->documents->create([
            'id' => (string) $document->getId(), // Unique ID is required
            'content' => $document->getContent(),
            'embedding' => $document->getEmbedding(),
            'sourceType' => $document->getSourceType(),
            'sourceName' => $document->getSourceName(),
            ...$document->metadata,
        ]);

        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        $this->client->collections[$this->collection]->documents->delete([
            "filter_by" => "sourceType:={$sourceType} && sourceName:={$sourceName}",
        ]);

        return $this;
    }

    /**
     * Bulk save.
     *
     * @param Document[] $documents
     * @throws Exception
     * @throws \JsonException
     * @throws TypesenseClientError
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        if (empty($documents[0]->getEmbedding())) {
            throw new \Exception('document embedding must be set before adding a document');
        }

        $this->checkIndexStatus($documents[0]);

        $lines = [];
        foreach ($documents as $document) {
            $lines[] = \json_encode([
                'id' => (string) $document->getId(), // Unique ID is required
                'embedding' => $document->getEmbedding(),
                'content' => $document->getContent(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                ...$document->metadata,
            ]);
        }

        $ndjson = \implode("\n", $lines);

        $this->client->collections[$this->collection]->documents->import($ndjson);

        return $this;
    }

    public function similaritySearch(array $embedding): array
    {
        $params = [
            'collection' => $this->collection,
            'q' => '*',
            'vector_query' => 'embedding:(' . \json_encode($embedding) . ')',
            'exclude_fields' => 'embedding',
            'per_page' => $this->topK,
            'num_candidates' => \max(50, \intval($this->topK) * 4),
        ];

        $searchRequests = ['searches' => [$params]];

        $response = $this->client->multiSearch->perform($searchRequests);
        return \array_map(function (array $hit): Document {
            $item = $hit['document'];
            $document = new Document($item['content']);
            //$document->embedding = $item['embedding']; // avoid carrying large data
            $document->sourceType = $item['sourceType'];
            $document->sourceName = $item['sourceName'];
            $document->score = VectorSimilarity::similarityFromDistance($hit['vector_distance']);

            foreach ($item as $name => $value) {
                if (!\in_array($name, ['content', 'sourceType', 'sourceName', 'score', 'embedding', 'id', 'vector_distance'])) {
                    $document->addMetadata($name, $value);
                }
            }

            return $document;
        }, $response['results'][0]['hits']);
    }

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
            \array_key_exists('num_dim', $embeddingField)
            && $embeddingField['num_dim'] === $dimension
        ) {
            return;
        }

        throw new \Exception(
            "Vector embeddings dimension {$dimension} must be the same as the initial setup {$this->vectorDimension} - ".
            \json_encode($embeddingField)
        );
    }
}
