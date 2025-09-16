<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\Attachments\Attachment;
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

class MessageMapper implements MessageMapperInterface
{
    /**
     * Mapped messages.
     */
    protected array $mapping = [];

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

    public function mapMessage(Message $message): void
    {
        $payload = $message->jsonSerialize();

        if (\array_key_exists('usage', $payload)) {
            unset($payload['usage']);
        }

        $attachments = $message->getAttachments();

        foreach ($attachments as $attachment) {
            if ($attachment->type === AttachmentType::DOCUMENT) {
                throw new ProviderException('This provider does not support document attachments.');
            }

            $payload['images'][] = $this->mapAttachment($attachment);
        }

        unset($payload['attachments']);

        $this->mapping[] = $payload;
    }

    protected function mapAttachment(Attachment $attachment): string
    {
        return match ($attachment->contentType) {
            AttachmentContentType::BASE64 => $attachment->content,
            AttachmentContentType::URL => throw new ProviderException('Ollama support only base64 attachment type.'),
        };
    }

    protected function mapToolCall(ToolCallMessage $message): void
    {
        $message = $message->jsonSerialize();

        if (\array_key_exists('usage', $message)) {
            unset($message['usage']);
        }

        if (\array_key_exists('tool_calls', $message)) {
            $message['tool_calls'] = \array_map(function (array $toolCall): array {
                if (empty($toolCall['function']['arguments'])) {
                    $toolCall['function']['arguments'] = new \stdClass();
                }
                return $toolCall;
            }, $message['tool_calls']);
        }

        unset($message['type']);
        unset($message['tools']);

        $this->mapping[] = $message;
    }

    public function mapToolsResult(ToolCallResultMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            $this->mapping[] = [
                'role' => MessageRole::TOOL->value,
                'content' => $tool->getResult()
            ];
        }
    }
}
