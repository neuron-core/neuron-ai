<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Deepseek;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Providers\OpenAI\MessageMapper as OpenAIMessageMapper;

class MessageMapper extends OpenAIMessageMapper
{
    protected function mapMessage(Message $message): array
    {
        $result = parent::mapMessage($message);

        if ($message->getMetadata('reasoning_content')) {
            $result['reasoning_content'] = $message->getMetadata('reasoning_content');
        }

        return $result;
    }

    protected function mapToolCall(ToolCallMessage $message): array
    {
        $result = parent::mapToolCall($message);

        if ($message->getMetadata('reasoning_content')) {
            $result['reasoning_content'] = $message->getMetadata('reasoning_content');
        }

        return $result;
    }
}
