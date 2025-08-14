<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use const PHP_INT_MAX;

class Document implements \JsonSerializable
{
    public const UUID = 'uuid';

    public const INT = 'int';

    public string|int $id;

    public array $embedding = [];

    public string $sourceType = 'manual';

    public string $sourceName = 'manual';

    public float $score = 0;

    public array $metadata = [];

    public function __construct(public string $content = '', ?string $idType = null) {
        $this->id = match ($idType) {
            'uuid' => $this->generateUuid(),
            'int' => \random_int(1, PHP_INT_MAX),
            default => \uniqid(),
        };
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

    private function generateUuid(): string
    {
        $data = \random_bytes(16);
        $data[6] = \chr(\ord($data[6]) & 0x0f | 0x40);
        $data[8] = \chr(\ord($data[8]) & 0x3f | 0x80);
        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($data), 4));
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
