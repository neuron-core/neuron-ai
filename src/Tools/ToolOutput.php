<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use JsonSerializable;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use Stringable;

use function array_filter;
use function array_map;
use function implode;

/**
 * Multimodal tool result. Return an instance from a tool's __invoke()
 * to send content blocks (text, images, documents, audio, video) back
 * to the model instead of a plain string.
 *
 * Providers whose API accepts content blocks in tool results map the
 * blocks natively; text-only providers fall back to getText().
 */
class ToolOutput implements JsonSerializable, Stringable
{
    /**
     * @param ContentBlockInterface[] $blocks
     */
    public function __construct(protected array $blocks)
    {
    }

    public static function text(string $content): self
    {
        return new self([new TextContent($content)]);
    }

    public static function image(string $content, SourceType $sourceType, ?string $mediaType = null): self
    {
        return new self([new ImageContent($content, $sourceType, $mediaType)]);
    }

    public static function file(string $content, SourceType $sourceType, ?string $mediaType = null, ?string $filename = null): self
    {
        return new self([new FileContent($content, $sourceType, $mediaType, $filename)]);
    }

    public static function audio(string $content, SourceType $sourceType, ?string $mediaType = null): self
    {
        return new self([new AudioContent($content, $sourceType, $mediaType)]);
    }

    public static function video(string $content, SourceType $sourceType, ?string $mediaType = null): self
    {
        return new self([new VideoContent($content, $sourceType, $mediaType)]);
    }

    /**
     * @return ContentBlockInterface[]
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * Text-only projection of the output: the concatenated text blocks.
     * Used as fallback for providers whose API does not accept content
     * blocks in tool results.
     */
    public function getText(): string
    {
        return implode(' ', array_map(
            fn (TextContent $block): string => $block->content,
            array_filter($this->blocks, fn (ContentBlockInterface $block): bool => $block instanceof TextContent)
        ));
    }

    public function __toString(): string
    {
        return $this->getText();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return array_map(fn (ContentBlockInterface $block): array => $block->toArray(), $this->blocks);
    }
}
