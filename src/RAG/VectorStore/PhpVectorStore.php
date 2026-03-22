<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\RAG\Document;

/**
 * PHP Vector Store — zero-dependency local vector store.
 *
 * Binary Float32 storage with Matryoshka multi-stage search and Int8 quantization.
 * No external services, no C extensions, no SQLite — pure PHP.
 *
 * Requires: mauricioperera/php-vector-store (composer require mauricioperera/php-vector-store)
 *
 * @see https://github.com/MauricioPerera/php-vector-store
 */
class PhpVectorStore implements VectorStoreInterface
{
    private \PHPVectorStore\VectorStore|\PHPVectorStore\QuantizedStore $store;

    /**
     * @param string $directory   Path to store vector files.
     * @param int    $dimensions  Vector dimensions (384 recommended for Matryoshka).
     * @param string $collection  Collection name.
     * @param int    $topK        Number of results for similarity search.
     * @param bool   $quantized   Use Int8 quantization (4x smaller, <0.001 score drift).
     * @param bool   $matryoshka  Use Matryoshka multi-stage search (3-5x faster).
     * @param int[]  $stages      Matryoshka stages (auto-detected from dimensions if empty).
     */
    public function __construct(
        protected string $directory,
        protected int $dimensions = 384,
        protected string $collection = 'documents',
        protected int $topK = 4,
        protected bool $quantized = true,
        protected bool $matryoshka = true,
        protected array $stages = [],
    ) {
        if (!class_exists(\PHPVectorStore\VectorStore::class)) {
            throw new \RuntimeException(
                'PHP Vector Store is not installed. Run: composer require mauricioperera/php-vector-store'
            );
        }

        $this->store = $quantized
            ? new \PHPVectorStore\QuantizedStore($directory, $dimensions)
            : new \PHPVectorStore\VectorStore($directory, $dimensions);

        if (empty($this->stages)) {
            $this->stages = self::defaultStages($dimensions);
        }
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        $this->store->set(
            $this->collection,
            (string) $document->getId(),
            $document->getEmbedding(),
            $this->documentToMeta($document),
        );
        $this->store->flush();
        return $this;
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        foreach ($documents as $document) {
            $this->store->set(
                $this->collection,
                (string) $document->getId(),
                $document->getEmbedding(),
                $this->documentToMeta($document),
            );
        }
        $this->store->flush();
        return $this;
    }

    /**
     * @deprecated Use deleteBy() instead.
     */
    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        foreach ($this->store->ids($this->collection) as $id) {
            $record = $this->store->get($this->collection, $id);
            if (!$record) {
                continue;
            }

            $meta = $record['metadata'] ?? [];
            if (($meta['sourceType'] ?? '') !== $sourceType) {
                continue;
            }
            if ($sourceName !== null && ($meta['sourceName'] ?? '') !== $sourceName) {
                continue;
            }

            $this->store->remove($this->collection, $id);
        }

        $this->store->flush();
        return $this;
    }

    public function similaritySearch(array $embedding): iterable
    {
        $results = $this->matryoshka
            ? $this->store->matryoshkaSearch($this->collection, $embedding, $this->topK, $this->stages)
            : $this->store->search($this->collection, $embedding, $this->topK);

        $documents = [];

        foreach ($results as $result) {
            $meta = $result['metadata'] ?? [];

            $document = new Document($meta['content'] ?? '');
            $document->id = $result['id'];
            $document->sourceType = $meta['sourceType'] ?? 'manual';
            $document->sourceName = $meta['sourceName'] ?? 'manual';
            $document->metadata = $meta['userMeta'] ?? [];
            $document->setScore($result['score']);

            $documents[] = $document;
        }

        return $documents;
    }

    private function documentToMeta(Document $document): array
    {
        return [
            'content'    => $document->getContent(),
            'sourceType' => $document->getSourceType(),
            'sourceName' => $document->getSourceName(),
            'userMeta'   => $document->metadata,
        ];
    }

    private static function defaultStages(int $dim): array
    {
        if ($dim <= 128) return [$dim];
        if ($dim <= 256) return [128, $dim];
        if ($dim <= 384) return [128, 256, $dim];
        return [128, 384, $dim];
    }
}
