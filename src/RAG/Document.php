<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use NeuronAI\UniqueIdGenerator;

class Document implements \JsonSerializable
{
    public string|int $id;

    public array $embedding = [];

    public string $sourceType = 'manual';

    public string $sourceName = 'manual';

    public float $score = 0;

    public array $metadata = [];

    public function __construct(
        public string $content = '',
    ) {
        $this->id = UniqueIdGenerator::generateId();
    }

    public function getId(): string|int
    {
        return $this->id;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getEmbedding(): array
    {
        return $this->embedding;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceName(): string
    {
        return $this->sourceName;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function setScore(float $score): Document
    {
        $this->score = $score;
        return $this;
    }

    public function addMetadata(string $key, string|int $value): Document
    {
        $this->metadata[$key] = $value;
        return $this;
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
            'metadata' => $this->metadata,
        ];
    }
}
