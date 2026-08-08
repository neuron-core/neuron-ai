<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use JsonSerializable;
use NeuronAI\Chat\Enums\MediaType;
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
     * @param bool $isError Marks the output as a tool failure the model should
     *                      see and recover from. Providers with a native error
     *                      flag on tool results (Anthropic, Bedrock) map it;
     *                      elsewhere the blocks carry the error text as-is.
     */
    public function __construct(protected array $blocks, protected bool $isError = false)
    {
    }

    public static function text(string $content): self
    {
        return new self([new TextContent($content)]);
    }

    /**
     * A conversational tool failure: the feedback becomes the tool result the
     * model sees, and the agent loop continues. Return this from a tool's
     * __invoke() (or from the agent's toolErrorHandler) for failures the model
     * should recover from; let exceptions escape for real bugs — they abort
     * the run.
     */
    public static function error(string $feedback): self
    {
        return new self([new TextContent($feedback)], true);
    }

    public static function image(string $content, SourceType $sourceType, string|MediaType|null $mediaType = null): self
    {
        return new self([new ImageContent($content, $sourceType, $mediaType)]);
    }

    public static function file(string $content, SourceType $sourceType, string|MediaType|null $mediaType = null, ?string $filename = null): self
    {
        return new self([new FileContent($content, $sourceType, $mediaType, $filename)]);
    }

    public static function audio(string $content, SourceType $sourceType, string|MediaType|null $mediaType = null): self
    {
        return new self([new AudioContent($content, $sourceType, $mediaType)]);
    }

    public static function video(string $content, SourceType $sourceType, string|MediaType|null $mediaType = null): self
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

    public function isError(): bool
    {
        return $this->isError;
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
     * A plain output serializes as its block array — the pre-existing wire
     * shape. An error output wraps the blocks so the flag survives the chat
     * history round-trip (see AbstractChatHistory::deserializeToolResult()).
     *
     * @return array<int|string, mixed>
     */
    public function jsonSerialize(): array
    {
        $blocks = array_map(fn (ContentBlockInterface $block): array => $block->toArray(), $this->blocks);

        if ($this->isError) {
            return ['is_error' => true, 'blocks' => $blocks];
        }

        return $blocks;
    }
}
