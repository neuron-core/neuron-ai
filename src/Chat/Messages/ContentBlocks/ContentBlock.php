<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Messages\HasMetadata;

use function array_filter;

abstract class ContentBlock implements ContentBlockInterface
{
    use HasMetadata;

    protected bool $cached = false;

    public function __construct(public string $content)
    {
    }

    /**
     * Ask for a prompt cache breakpoint right after this block: the provider may
     * reuse the request up to and including it. The marker is stored with the
     * block, and a provider without cache breakpoints ignores it.
     */
    public function cache(): static
    {
        $this->cached = true;
        return $this;
    }

    public function isCached(): bool
    {
        return $this->cached;
    }

    public function accumulateContent(string $content): void
    {
        $this->content .= $content;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Leaves out the fields a block does not have (null, empty meta) and keeps every string,
     * so an empty upload's '' content survives the round trip.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected function withoutAbsentFields(array $fields): array
    {
        return array_filter($fields, fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * The cache marker as a serialized field, left out of a block that has none.
     *
     * @return array{cached?: true}
     */
    protected function cacheField(): array
    {
        return $this->cached ? ['cached' => true] : [];
    }
}
