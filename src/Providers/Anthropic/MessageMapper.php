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
use stdClass;

use function array_key_exists;
use function array_map;
use function is_string;

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

        if (array_key_exists('usage', $payload)) {
            unset($payload['usage']);
        }

        $attachments = $message->getAttachments();

        if (is_string($payload['content']) && $attachments) {
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
        $parts = [];

        // Add text content if present (Anthropic supports text + tool_use in content array)
        $content = $message->getContent();
        if (is_string($content) && $content !== '') {
            $parts[] = [
                'type' => 'text',
                'text' => $content,
            ];
        }

        // Add tool call blocks from the tools array
        foreach ($message->getTools() as $tool) {
            $parts[] = [
                'type' => 'tool_use',
                'id' => $tool->getCallId(),
                'name' => $tool->getName(),
                'input' => $tool->getInputs() ?: new stdClass(),
            ];
        }

        return [
            'role' => MessageRole::ASSISTANT->value,
            'content' => $parts,
        ];
    }

    protected function mapToolsResult(ToolCallResultMessage $message): array
    {
        return [
            'role' => MessageRole::USER,
            'content' => array_map(fn (ToolInterface $tool): array => [
                'type' => 'tool_result',
                'tool_use_id' => $tool->getCallId(),
                'content' => $tool->getResult(),
            ], $message->getTools())
        ];
    }
}
