<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;

use function ceil;
use function json_encode;
use function mb_strlen;
use function array_reduce;

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
                $messageChars = $this->calculateMessageChars($message);
                // Round up per message to ensure individual counts add up correctly
                $tokenCount += ceil($messageChars / $this->charsPerToken);
            }

            // Add extra tokens per message
            $tokenCount += $this->extraTokensPerMessage;
        }

        // Final round up in case extraTokensPerMessage is a float
        return (int) ceil($tokenCount);
    }

    protected function calculateMessageChars(Message $message): int
    {
        // Count role characters
        $messageTokens = mb_strlen($message->getRole());

        if ($message instanceof ToolResultMessage) {
            $messageTokens += $this->handleToolResult($message);
        }

        return $messageTokens + array_reduce(
            $message->getContentBlocks(),
            fn(int $carry, ContentBlockInterface $block): int => $carry + match ($block::class) {
                TextContent::class, ReasoningContent::class => $this->handleTextBlock($block),
                default => 0,
            },
            0
        );
    }

    protected function handleToolResult(ToolResultMessage $message): int
    {
        return array_reduce(
            $message->getTools(),
            function (int $carry, ToolInterface $tool): int {
                $carry += mb_strlen($tool->getResult());

                if ($tool->getCallId() !== null) {
                    $carry += mb_strlen($tool->getCallId());
                }

                return $carry;
            },
            0
        );
    }

    protected function handleTextBlock(TextContent $block): int
    {
        return mb_strlen(
            json_encode($block->toArray())
        );
    }
}
