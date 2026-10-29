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

    protected function mapToolCall(ToolCallMessage $message): array
    {
        return $this->withReasoningContent(parent::mapToolCall($message), $message);
    }

    /**
     * A reasoner turn must carry the field even when the model returned an empty
     * string: DeepSeek rejects an assistant message without reasoning_content.
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
