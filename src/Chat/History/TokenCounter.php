<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;

use function ceil;
use function json_encode;
use function mb_strlen;
use function array_reduce;
use function base64_decode;
use function filter_var;
use function getimagesize;
use function getimagesizefromstring;
use function preg_match;
use function str_starts_with;
use function strpos;
use function substr;

use const FILTER_VALIDATE_URL;

class TokenCounter implements TokenCounterInterface
{
    public function __construct(
        protected float $charsPerToken = 4.0,
        protected float $extraTokensPerMessage = 3.0
    ) {
    }

    /**
     * @param Message[] $messages
     */
    public function count(array $messages): int
    {
        $tokenCount = 0.0;

        foreach ($messages as $message) {
            // Handle assistant messages with usage data
            if ($message instanceof AssistantMessage && $usage = $message->getUsage()) {
                $tokenCount += $usage->outputTokens;
            } else {
                $tokenCount += $this->calculateMessageTokens($message);
            }

            // Add extra tokens per message
            $tokenCount += $this->extraTokensPerMessage;
        }

        // Final round up in case extraTokensPerMessage is a float
        return (int) ceil($tokenCount);
    }

    protected function tokens(int $chars): float
    {
        return ceil($chars / $this->charsPerToken);
    }

    protected function calculateMessageTokens(Message $message): float
    {
        if ($message instanceof ToolResultMessage) {
            return $this->handleToolResult($message);
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

        return $this->tokens((int) ceil($chars));
    }

    protected function handleToolResult(ToolResultMessage $message): float
    {
        // Count role characters
        $chars = mb_strlen($message->getRole());

        $chars = array_reduce(
            $message->getTools(),
            function (int $carry, ToolInterface $tool): int {
                $carry += mb_strlen($tool->getResult());

                if ($tool->getCallId() !== null) {
                    $carry += mb_strlen($tool->getCallId());
                }

                return $carry;
            },
            $chars
        );

        return $this->tokens($chars);
    }

    protected function handleTextBlock(TextContent $block): int
    {
        return mb_strlen(json_encode($block->toArray()));
    }

    protected function handleImageBlock(ImageContent $block): int
    {
        $input = $block->getContent();

        // 1. Check if the input is a Base64 string
        // We look for the "data:" scheme or check if it's a valid base64 blob
        if (str_starts_with($input, 'data:image') || !filter_var($input, FILTER_VALIDATE_URL)) {

            // Strip the prefix if it exists
            if (preg_match('/^data:image\/(\w+);base64,/', $input)) {
                $input = substr($input, strpos($input, ',') + 1);
            }

            $data = base64_decode($input, true);

            // If decoding succeeded, treat as string data
            if ($data) {
                $size = getimagesizefromstring($data);
                return $this->calculateImageChars($size[0], $size[1]);
            }
        }

        // 2. Otherwise, treat it as a URL or File Path
        // getimagesize() handles local paths and remote URLs (if allow_url_fopen is on)
        $size = @getimagesize($input);

        if ($size) {
            return $this->calculateImageChars($size[0], $size[1]);
        }

        return 0;
    }

    protected function calculateImageChars(int $width, int $height): int
    {
        // 2. Scale down to fit within a 2048 x 2048 square if necessary
        if ($width > 2048 || $height > 2048) {
            $aspectRatio = $width / $height;
            if ($aspectRatio > 1) {
                $width = 2048;
                $height = (int)(2048 / $aspectRatio);
            } else {
                $height = 2048;
                $width = (int)(2048 * $aspectRatio);
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
