<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use NeuronAI\Chat\Attachments\Attachment;
use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Chat\Enums\AttachmentType;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;

class MessageMapperResponses implements MessageMapperInterface
{
    protected array $mapping = [];

    /**
     * @throws ProviderException
     */
    public function map(array $messages): array
    {
        $this->mapping = [];

        foreach ($messages as $message) {
            match ($message::class) {
                Message::class,
                UserMessage::class,
                AssistantMessage::class => $this->mapMessage($message),
                ToolCallMessage::class => $this->mapToolCall($message),
                ToolResultMessage::class => $this->mapToolsResult($message),
                default => throw new ProviderException('Could not map message type '.$message::class),
            };
        }

        return $this->mapping;
    }

    /**
     * @throws ProviderException
     */
    protected function mapMessage(Message $message): void
    {
        $payload['role'] = $message->getRole();
        $contentBlocks = $message->getContent();

        // Map content blocks to provider format
        $payload['content'] = [];
        foreach ($contentBlocks as $block) {
            if ($block instanceof \NeuronAI\Chat\ContentBlocks\TextContentBlock) {
                $payload['content'][] = [
                    'type' => $this->isUserMessage($message) ? 'input_text' : 'output_text',
                    'text' => $block->text,
                ];
            } elseif ($block instanceof \NeuronAI\Chat\ContentBlocks\FileContentBlock) {
                if ($block->sourceType === \NeuronAI\Chat\Enums\SourceType::URL) {
                    throw new ProviderException('This provider does not support URL document attachments.');
                }
                $payload['content'][] = [
                    'type' => 'file',
                    'file' => [
                        'filename' => $block->filename ?? "attachment-".\uniqid().".pdf",
                        'file_data' => "data:{$block->mediaType};base64,{$block->source}",
                    ]
                ];
            } elseif ($block instanceof \NeuronAI\Chat\ContentBlocks\ImageContentBlock) {
                $url = match ($block->sourceType) {
                    \NeuronAI\Chat\Enums\SourceType::URL => $block->source,
                    \NeuronAI\Chat\Enums\SourceType::BASE64 => 'data:'.$block->mediaType.';base64,'.$block->source,
                };
                $payload['content'][] = [
                    'type' => 'input_image',
                    'image_url' => $url,
                ];
            }
        }

        $this->mapping[] = $payload;
    }

    protected function isUserMessage(Message $message): bool
    {
        return $message instanceof UserMessage || $message->getRole() === MessageRole::USER->value;
    }

    public function mapDocumentAttachment(Attachment $attachment): array
    {
        return match ($attachment->contentType) {
            AttachmentContentType::URL => [
                'type' => 'input_file',
                'file_url' => $attachment->content,
            ],
            AttachmentContentType::BASE64 => [
                'type' => 'input_file',
                'filename' => "attachment-".\uniqid().".pdf",
                'file_data' => "data:{$attachment->mediaType};base64,{$attachment->content}",
            ]
        };
    }

    protected function mapImageAttachment(Attachment $attachment): array
    {
        return match($attachment->contentType) {
            AttachmentContentType::URL => [
                'type' => 'input_image',
                'image_url' => $attachment->content,
            ],
            AttachmentContentType::BASE64 => [
                'type' => 'input_image',
                'image_url' => 'data:'.$attachment->mediaType.';base64,'.$attachment->content,
            ]
        };
    }

    protected function mapToolCall(ToolCallMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            $inputs = $tool->getInputs();
            $this->mapping[] = [
                'type' => 'function_call',
                'name' => $tool->getName(),
                'arguments' => \json_encode($inputs !== [] ? $inputs : new \stdClass()),
                'call_id' => $tool->getCallId(),
            ];
        }
    }

    protected function mapToolsResult(ToolResultMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            $this->mapping[] = [
                'type' => 'function_call_output',
                'call_id' => $tool->getCallId(),
                'output' => $tool->getResult(),
            ];
        }
    }
}
