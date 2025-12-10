<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

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

use function array_key_exists;
use function is_string;
use function uniqid;
use function array_is_list;
use function array_merge;

class MessageMapper implements MessageMapperInterface
{
    protected array $mapping = [];

    /**
     * @throws ProviderException
     */
    public function map(array $messages): array
    {
        $this->mapping = [];

        foreach ($messages as $message) {
            $item = match ($message::class) {
                Message::class,
                UserMessage::class,
                AssistantMessage::class => $this->mapMessage($message),
                ToolCallMessage::class => $this->mapToolCall($message),
                ToolCallResultMessage::class => $this->mapToolsResult($message),
                default => throw new ProviderException('Could not map message type '.$message::class),
            };

            if (array_is_list($item)) {
                $this->mapping = array_merge($this->mapping, $item);
            } else {
                $this->mapping[] = $item;
            }
        }

        return $this->mapping;
    }

    /**
     * @throws ProviderException
     */
    protected function mapMessage(Message $message): array
    {
        $payload = $message->jsonSerialize();

        if (array_key_exists('usage', $payload)) {
            unset($payload['usage']);
        }

        $attachments = $message->getAttachments();

        if (is_string($payload['content'])) {
            $payload['content'] = [
                [
                    'type' => 'text',
                    'text' => $payload['content'],
                ],
            ];
        }

        foreach ($attachments as $attachment) {
            if ($attachment instanceof Document) {
                if ($attachment->contentType === AttachmentContentType::URL) {
                    // OpenAI does not support URL type
                    throw new ProviderException('OpenAI does not support URL document attachments.');
                }

                $payload['content'][] = $this->mapDocumentAttachment($attachment);
            } elseif ($attachment->type === AttachmentType::IMAGE) {
                $payload['content'][] = $this->mapImageAttachment($attachment);
            }
        }

        unset($payload['attachments']);

        return $payload;
    }

    public function mapDocumentAttachment(Document $document): array
    {
        return match ($document->contentType) {
            AttachmentContentType::BASE64 => [
                'type' => 'file',
                'file' => [
                    'filename' => $document->filename ?? "attachment-".uniqid().".pdf",
                    'file_data' => "data:{$document->mediaType};base64,{$document->content}",
                ]
            ],
            AttachmentContentType::FILE_ID => [
                'type' => 'file',
                'file' => [
                    'file_id' => $document->content,
                ]
            ],
        };
    }

    protected function mapImageAttachment(Attachment $attachment): array
    {
        return match($attachment->contentType) {
            AttachmentContentType::URL => [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $attachment->content,
                ],
            ],
            AttachmentContentType::BASE64 => [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:'.$attachment->mediaType.';base64,'.$attachment->content,
                ],
            ],
            AttachmentContentType::FILE_ID => [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $attachment->content,
                ],
            ],
        };
    }

    protected function mapToolCall(ToolCallMessage $message): array
    {
        $message = $message->jsonSerialize();

        if (array_key_exists('usage', $message)) {
            unset($message['usage']);
        }

        unset($message['type']);
        unset($message['tools']);

        return $message;
    }

    protected function mapToolsResult(ToolCallResultMessage $message): array
    {
        $items = [];

        foreach ($message->getTools() as $tool) {
            $items[] = [
                'role' => MessageRole::TOOL->value,
                'tool_call_id' => $tool->getCallId(),
                'content' => $tool->getResult()
            ];
        }

        return $items;
    }
}
