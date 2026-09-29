<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolCall;

use function ceil;
use function json_encode;
use function mb_strlen;
use function array_reduce;
use function base64_decode;
use function getimagesizefromstring;
use function max;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

class TokenCounter
{
    public function __construct(
        protected float $charsPerToken = 4.0,
        protected float $extraTokensPerMessage = 3.0
    ) {
    }

    public function count(Message $message): int
    {
        if ($message instanceof ToolResultMessage) {
            return (int) $this->handleToolResult($message);
        }

        // Start processing user messages
        // Count role characters
        $chars = mb_strlen($message->getRole());

        // Calculate chars contribution of blocks
        $chars = array_reduce(
            $message->getContentBlocks(),
            fn (float $carry, ContentBlockInterface $block): float => $carry + match ($block::class) {
                TextContent::class, ReasoningContent::class => $this->handleTextBlock($block),
                ImageContent::class => $this->handleImageBlock($block),
                default => 200 * $this->charsPerToken, // Audio and video blocks are not supported yet (fallback to 100 tokens)
            },
            $chars
        );

        if ($message instanceof ToolCallMessage) {
            $chars += $this->handleToolCalls($message);
        }

        return (int) $this->tokens((int) ceil($chars));
    }

    protected function tokens(int $chars): float
    {
        return ceil($chars / $this->charsPerToken);
    }

    protected function handleToolResult(ToolResultMessage $message): float
    {
        // Count role characters
        $chars = mb_strlen($message->getRole());

        $chars = array_reduce(
            $message->getToolCalls(),
            function (int $carry, ToolCall $tool): int {
                $carry += mb_strlen((string) $tool->getResult());

                if ($tool->getCallId() !== null) {
                    $carry += mb_strlen($tool->getCallId());
                }

                return $carry;
            },
            $chars
        );

        return $this->tokens($chars);
    }

    /**
     * What the model reads of each call: its name, its ID and its arguments.
     */
    protected function handleToolCalls(ToolCallMessage $message): int
    {
        return array_reduce(
            $message->getToolCalls(),
            fn (int $carry, ToolCall $call): int => $carry
                + mb_strlen($call->getName())
                + mb_strlen((string) $call->getCallId())
                + mb_strlen(json_encode(
                    $call->getInputs(),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
                )),
            0
        );
    }

    protected function handleTextBlock(TextContent $block): int
    {
        return mb_strlen(json_encode($block->toArray()));
    }

    protected function handleImageBlock(ImageContent $block): int
    {
        // A URL or an ID is never opened: counting would fetch a host or read a file chosen by the message's author
        $data = $block->sourceType === SourceType::BASE64 ? base64_decode($block->getContent(), true) : false;
        $size = $data === false ? false : @getimagesizefromstring($data);

        // Without a readable size, the image is priced like a common 1024 x 1024 upload until the provider reports its usage.
        // PHP 8.5+ reads an SVG's declared size, but a vector has no pixel size to price.
        if ($size === false || $size['mime'] === 'image/svg+xml' || $size[0] < 1 || $size[1] < 1) {
            return $this->calculateImageChars(1024, 1024);
        }

        return $this->calculateImageChars($size[0], $size[1]);
    }

    protected function calculateImageChars(int $width, int $height): int
    {
        // 2. Scale down to fit within a 2048 x 2048 square if necessary, keeping at least one pixel per side
        if ($width > 2048 || $height > 2048) {
            $aspectRatio = $width / $height;
            if ($aspectRatio > 1) {
                $width = 2048;
                $height = max(1, (int)(2048 / $aspectRatio));
            } else {
                $height = 2048;
                $width = max(1, (int)(2048 * $aspectRatio));
            }
        }

        // 3. Resize such that the shortest side is 768px
        $minSize = 768;
        $aspectRatio = $width / $height;

        // Check if both sides exceed 768 to perform the "shortest side" resize
        if ($width > $minSize && $height > $minSize) {
            if ($aspectRatio > 1) {
                $height = $minSize;
                $width = (int)($minSize * $aspectRatio);
            } else {
                $width = $minSize;
                $height = (int)($minSize / $aspectRatio);
            }
        }

        // 4. Calculate tiles (Ceiling division)
        $tilesWidth = ceil($width / 512);
        $tilesHeight = ceil($height / 512);

        // 5. Total cost: base cost (85) + 170 per tile
        $chars = (85 * $this->charsPerToken) + (170 * $this->charsPerToken) * ($tilesWidth * $tilesHeight);
        return (int) $chars;
    }
}
