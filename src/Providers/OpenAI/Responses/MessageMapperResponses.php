<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use NeuronAI\Chat\Attachments\Attachment;
use NeuronAI\Chat\Attachments\Document;
use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Chat\Enums\AttachmentType;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;
use stdClass;

use function is_array;
use function is_string;
use function json_encode;
use function uniqid;

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
                ToolCallResultMessage::class => $this->mapToolsResult($message),
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

        if (is_array($message->getContent())) {
            $payload['content'] = $message->getContent();
        } else {
            $payload['content'] = [
                [
                    'type' => $this->isUserMessage($message) ? 'input_text' : 'output_text',
                    'text' => (string) $message->getContent(),
                ],
            ];
        }
        foreach ($message->getAttachments() as $attachment) {
            if ($attachment instanceof Document) {
                if ($attachment->contentType === AttachmentContentType::URL) {
                    // OpenAI does not support URL type
                    throw new ProviderException('This provider does not support URL document attachments.');
                }

                $payload['content'][] = $this->mapDocumentAttachment($attachment);
            } elseif ($attachment->type === AttachmentType::IMAGE) {
                $payload['content'][] = $this->mapImageAttachment($attachment);
            }
        }

        $this->mapping[] = $payload;
    }

    protected function isUserMessage(Message $message): bool
    {
        return $message instanceof UserMessage || $message->getRole() === MessageRole::USER->value;
    }

    public function mapDocumentAttachment(Document $document): array
    {
        return match ($document->contentType) {
            AttachmentContentType::URL => [
                'type' => 'input_file',
                'file_url' => $document->content,
            ],
            AttachmentContentType::BASE64 => [
                'type' => 'input_file',
                'filename' => $document->filename ?? "attachment-".uniqid().".pdf",
                'file_data' => "data:{$document->mediaType};base64,{$document->content}",
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
        // Add text content if present (OpenAI Responses supports text + function_call)
        $text = $message->getContent();
        if (is_string($text) && $text !== '') {
            $this->mapping[] = [
                'role' => $message->getRole(),
                'content' => $text,
            ];
        }

        // Add function call items
        foreach ($message->getTools() as $tool) {
            $inputs = $tool->getInputs();
            $this->mapping[] = [
                'type' => 'function_call',
                'name' => $tool->getName(),
                'arguments' => json_encode($inputs !== [] ? $inputs : new stdClass()),
                'call_id' => $tool->getCallId(),
            ];
        }
    }

    protected function mapToolsResult(ToolCallResultMessage $message): void
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
