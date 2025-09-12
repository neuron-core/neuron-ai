<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

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
        $payload = [
            'role' => $message->getRole() === MessageRole::ASSISTANT->value ? MessageRole::MODEL->value : $message->getRole(),
            'parts' => [
                ['text' => $message->getContent()]
            ],
        ];

        $attachments = $message->getAttachments();

        foreach ($attachments as $attachment) {
            $payload['parts'][] = $this->mapAttachment($attachment);
        }

        return $payload;
    }

    protected function mapAttachment(Attachment $attachment): array
    {
        return match($attachment->contentType) {
            AttachmentContentType::URL => [
                'file_data' => [
                    'file_uri' => $attachment->content,
                    'mime_type' => $attachment->mediaType,
                ],
            ],
            AttachmentContentType::BASE64 => [
                'inline_data' => [
                    'data' => $attachment->content,
                    'mime_type' => $attachment->mediaType,
                ]
            ]
        };
    }

    protected function mapToolCall(ToolCallMessage $message): array
    {
        return [
            'role' => MessageRole::MODEL->value,
            'parts' => [
                ...\array_map(fn (ToolInterface $tool): array => [
                    'functionCall' => [
                        'name' => $tool->getName(),
                        'args' => $tool->getInputs() !== [] ? $tool->getInputs() : new \stdClass(),
                    ]
                ], $message->getTools())
            ]
        ];
    }

    protected function mapToolsResult(ToolCallResultMessage $message): array
    {
        return [
            'role' => MessageRole::USER->value,
            'parts' => \array_map(fn (ToolInterface $tool): array => [
                'functionResponse' => [
                    'name' => $tool->getName(),
                    'response' => [
                        'name' => $tool->getName(),
                        'content' => $tool->getResult(),
                    ],
                ],
            ], $message->getTools()),
        ];
    }
}
