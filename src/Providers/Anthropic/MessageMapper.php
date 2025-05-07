<?php

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\Image;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\ToolInterface;

class MessageMapper implements MessageMapperInterface
{
    protected array $mapping = [];

    public function map(array $messages): array
    {
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

    protected function mapMessage(Message $message): void
    {
        $payload = $message->jsonSerialize();

        if (\array_key_exists('usage', $payload)) {
            unset($payload['usage']);
        }

        if ($images = $message->getImages()) {
            $payload['content'] = [
                [
                    'type' => 'text',
                    'text' => $payload['content'],
                ],
            ];

            foreach ($images as $image) {
                $payload['content'][] = $this->mapImage($image);
            }

            unset($payload['images']);
        }

        $this->mapping[] = $payload;
    }

    protected function mapImage(Image $image): array
    {
        return match($image->type) {
            Image::TYPE_URL => [
                'type' => 'image',
                'source' => [
                    'type' => 'url',
                    'url' => $image->image,
                ],
            ],
            Image::TYPE_BASE64 => [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image->mediaType,
                    'data' => $image->image,
                ],
            ],
            default => throw new ProviderException('Invalid image type '.$image->type),
        };
    }

    protected function mapToolCall(ToolCallMessage $message): void
    {
        $message = $message->jsonSerialize();

        if (\array_key_exists('usage', $message)) {
            unset($message['usage']);
        }

        unset($message['type']);
        unset($message['tools']);

        $this->mapping[] = $message;
    }

    protected function mapToolsResult(ToolCallResultMessage $message): void
    {
        $this->mapping[] = [
            'role' => Message::ROLE_USER,
            'content' => \array_map(function (ToolInterface $tool) {
                return [
                    'type' => 'tool_result',
                    'tool_use_id' => $tool->getCallId(),
                    'content' => $tool->getResult(),
                ];
            }, $message->getTools())
        ];
    }
}
