<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use JsonSerializable;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;

use function array_map;
use function implode;

/**
 * Carrier for a tool's execution result. Tools that only return text can keep
 * returning strings; tools that need to emit rich content (text + images,
 * documents, etc.) return a ToolOutput built via ToolOutput::blocks().
 */
final class ToolOutput implements JsonSerializable
{
    /**
     * @param ContentBlockInterface[] $blocks
     */
    public function __construct(
        public readonly ?string $text = null,
        public readonly array $blocks = [],
    ) {
    }

    public static function text(string $text): self
    {
        return new self(text: $text);
    }

    /**
     * @param ContentBlockInterface[] $blocks
     */
    public static function blocks(array $blocks): self
    {
        return new self(blocks: $blocks);
    }

    public function hasBlocks(): bool
    {
        return $this->blocks !== [];
    }

    /**
     * @return ContentBlockInterface[]
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * Returns the explicit text payload, or the concatenation of TextContent
     * blocks when no explicit text was set. Returns null when neither text nor
     * any text-bearing block is present.
     */
    public function getText(): ?string
    {
        if ($this->text !== null) {
            return $this->text;
        }

        $texts = [];
        foreach ($this->blocks as $block) {
            if ($block instanceof TextContent && $block->content !== '') {
                $texts[] = $block->content;
            }
        }

        return $texts === [] ? null : implode(' ', $texts);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'text' => $this->text,
            'blocks' => array_map(
                static fn (ContentBlockInterface $block): array => $block->toArray(),
                $this->blocks,
            ),
        ];
    }
}
