<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

class Citation implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly ?string $title = null,
        public readonly ?int $startIndex = null,
        public readonly ?int $endIndex = null,
        public readonly ?string $citedText = null,
        public readonly array $metadata = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'title' => $this->title,
            'start_index' => $this->startIndex,
            'end_index' => $this->endIndex,
            'cited_text' => $this->citedText,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            source: $data['source'],
            title: $data['title'] ?? null,
            startIndex: $data['start_index'] ?? null,
            endIndex: $data['end_index'] ?? null,
            citedText: $data['cited_text'] ?? null,
            metadata: $data['metadata'] ?? []
        );
    }
}
