<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use JsonSerializable;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\UniqueIdGenerator;

use function array_keys;
use function array_map;
use function in_array;
use function is_float;
use function is_int;
use function is_string;

final class Document implements JsonSerializable
{
    protected string|int $id;

    /**
     * @var float[]|null
     */
    protected ?array $embedding = null;

    protected string $sourceType = 'manual';

    protected string $sourceName = 'manual';

    protected ?float $score = null;

    /**
     * @var array<string, mixed>
     */
    protected array $metadata = [];

    protected string $content;

    public function __construct(string $content = '')
    {
        $this->content = $content;
        $this->id = UniqueIdGenerator::generateUUID();
    }

    public function getId(): string|int
    {
        return $this->id;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return float[]|null
     */
    public function getEmbedding(): ?array
    {
        return $this->embedding;
    }

    /**
     * @param array<int, int|float>|null $embedding
     */
    public function setEmbedding(?array $embedding): self
    {
        if ($embedding === null) {
            $this->embedding = null;

            return $this;
        }

        if ($embedding === []) {
            throw new DocumentSchemaException('Document embedding cannot be empty.');
        }

        foreach ($embedding as $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new DocumentSchemaException('Document embedding accepts numeric values only.');
            }
        }

        $this->embedding = array_map(static fn (int|float $value): float => (float) $value, $embedding);

        return $this;
    }

    public function setId(string|int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function setSourceType(string $sourceType): self
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getSourceName(): string
    {
        return $this->sourceName;
    }

    public function setSourceName(string $sourceName): self
    {
        $this->sourceName = $sourceName;

        return $this;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function setScore(?float $score): self
    {
        $this->score = $score;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): self
    {
        foreach (array_keys($metadata) as $key) {
            if (!is_string($key)) {
                throw new DocumentSchemaException('Document metadata must be an associative array with string keys.');
            }

            $this->guardMetadataKey($key);
        }

        $this->metadata = $metadata;

        return $this;
    }

    public function addMetadata(string $key, mixed $value): self
    {
        $this->guardMetadataKey($key);
        $this->metadata[$key] = $value;
        return $this;
    }

    protected function guardMetadataKey(string $key): void
    {
        if ($key === '') {
            throw new DocumentSchemaException('Document metadata field name cannot be empty.');
        }

        if (in_array($key, DocumentSchema::RESERVED_FIELDS, true)) {
            throw new DocumentSchemaException("Document metadata field \"{$key}\" is reserved by the framework.");
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->getId(),
            'content' => $this->getContent(),
            'embedding' => $this->getEmbedding(),
            'sourceType' => $this->getSourceType(),
            'sourceName' => $this->getSourceName(),
            'score' => $this->getScore(),
            'metadata' => $this->getMetadata(),
        ];
    }
}
