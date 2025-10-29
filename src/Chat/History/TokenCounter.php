<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;

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
            $messageChars = 0;

            $messageChars += \strlen(
                \json_encode(
                    \array_map(fn (ContentBlock $block): array => $block->toArray(), $message->getContent())
                )
            );

            // Handle tool calls
            if ($message instanceof ToolCallMessage) {
                foreach ($message->getTools() as $tool) {
                    $messageChars += \strlen(\json_encode($tool->getInputs()));

                    if ($tool->getCallId() !== null) {
                        $messageChars += \strlen($tool->getCallId());
                    }
                }
            }

            // Handle tool call results
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getTools() as $tool) {
                    $messageChars += \strlen($tool->getResult());

                    if ($tool->getCallId() !== null) {
                        $messageChars += \strlen($tool->getCallId());
                    }
                }
            }

            // Count role characters
            $messageChars += \strlen($message->getRole());

            // Round up per message to ensure individual counts add up correctly
            $tokenCount += \ceil($messageChars / $this->charsPerToken);

            // Add extra tokens per message
            $tokenCount += $this->extraTokensPerMessage;
        }

        // Final round up in case extraTokensPerMessage is a float
        return (int) \ceil($tokenCount);
    }
}
