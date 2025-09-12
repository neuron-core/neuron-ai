<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Attachments\Attachment;
use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\ToolInterface;

class MessageMapper implements MessageMapperInterface
{
    public function map(array $messages): array
    {
        $mapping = [];

        foreach ($messages as $message) {
            $mapping[] = match ($message::class) {
                Message::class,
                UserMessage::class,
                AssistantMessage::class => $this->mapMessage($message),
                ToolCallMessage::class => $this->mapToolCall($message),
                ToolCallResultMessage::class => $this->mapToolsResult($message),
                default => throw new ProviderException('Could not map message type '.$message::class),
            };
        }

        return $mapping;
    }

    protected function mapMessage(Message $message): array
    {
        $payload = $message->jsonSerialize();

        if (\array_key_exists('usage', $payload)) {
            unset($payload['usage']);
        }

        $attachments = $message->getAttachments();

        if (\is_string($payload['content']) && $attachments) {
            $payload['content'] = [
                [
                    'type' => 'text',
                    'text' => $payload['content'],
                ],
            ];
        }

        foreach ($attachments as $attachment) {
            $payload['content'][] = $this->mapAttachment($attachment);
        }

        unset($payload['attachments']);

        return $payload;
    }

    protected function mapAttachment(Attachment $attachment): array
    {
        return match($attachment->contentType) {
            AttachmentContentType::URL => [
                'type' => $attachment->type->value,
                'source' => [
                    'type' => 'url',
                    'url' => $attachment->content,
                ],
            ],
            AttachmentContentType::BASE64 => [
                'type' => $attachment->type->value,
                'source' => [
                    'type' => 'base64',
                    'media_type' => $attachment->mediaType,
                    'data' => $attachment->content,
                ],
            ],
        };
    }

    protected function mapToolCall(ToolCallMessage $message): array
    {
        $message = $message->jsonSerialize();

        if (\array_key_exists('usage', $message)) {
            unset($message['usage']);
        }

        unset($message['type']);
        unset($message['tools']);

        return $message;
    }

    protected function mapToolsResult(ToolCallResultMessage $message): array
    {
        return [
            'role' => MessageRole::USER,
            'content' => \array_map(fn (ToolInterface $tool): array => [
                'type' => 'tool_result',
                'tool_use_id' => $tool->getCallId(),
                'content' => $tool->getResult(),
            ], $message->getTools())
        ];
    }
}
