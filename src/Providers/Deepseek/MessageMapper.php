<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Deepseek;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\OpenAI\MessageMapper as OpenAIMessageMapper;

class MessageMapper extends OpenAIMessageMapper
{
    protected function mapMessage(Message $message): array
    {
        return $this->withReasoningContent(parent::mapMessage($message), $message);
    }

    /**
     * Thinking mode refuses a tool call it has no reasoning for, one from another provider
     * or from a turn with thinking off, unless the call carries reasoning_content: an empty
     * one is accepted.
     */
    protected function mapToolCall(ToolCallMessage $message): array
    {
        return $this->withReasoningContent(parent::mapToolCall($message), $message) + ['reasoning_content' => ''];
    }

    /**
     * The reasoning goes back as the model returned it, an empty string included.
     * A message that stored no reasoning has none to send.
     *
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    protected function withReasoningContent(array $mapped, Message $message): array
    {
        $reasoning = $message->getMetadata('reasoning_content');

        if ($reasoning !== null) {
            $mapped['reasoning_content'] = $reasoning;
        }

        return $mapped;
    }
}
