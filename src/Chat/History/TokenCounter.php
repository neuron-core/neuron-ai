<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;

class TokenCounter implements TokenCounterInterface
{
    public function __construct(
        protected float $charsPerToken = 4.0,
        protected float $extraTokensPerMessage = 3.0
    ) {
    }

    public function count(array $messages): int
    {
        $tokenCount = 0.0;

        foreach ($messages as $message) {
            $messageChars = 0;

            // Count content characters
            $content = $message->getContent();
            if (\is_string($content)) {
                $messageChars += \strlen($content);
            } elseif ($content !== null) {
                // For arrays and other types, use JSON representation
                $messageChars += \strlen(\json_encode($content));
            }

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
            if ($message instanceof ToolCallResultMessage) {
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
