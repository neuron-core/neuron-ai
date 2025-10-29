<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\ContentBlocks\ContentBlock;
use NeuronAI\Chat\ContentBlocks\ImageContentBlock;
use NeuronAI\Chat\ContentBlocks\TextContentBlock;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;

class MessageMapper implements MessageMapperInterface
{
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
                ToolResultMessage::class => $this->mapToolsResult($message),
                default => throw new ProviderException('Could not map message type '.$message::class),
            };
        }

        return $this->mapping;
    }

    public function mapMessage(Message $message): void
    {
        $contentBlocks = $message->getContent();
        $textContent = '';
        $images = [];

        foreach ($contentBlocks as $block) {
            if ($block instanceof TextContentBlock) {
                $textContent .= $block->text;
            } elseif ($block instanceof ImageContentBlock) {
                if ($block->sourceType === SourceType::URL) {
                    throw new ProviderException('Ollama supports only base64 image type.');
                }
                $images[] = $block->source;
            } else {
                throw new ProviderException('This provider does not support '.$block::class.' content blocks.');
            }
        }

        $payload = [
            'role' => $message->getRole(),
            'content' => $textContent,
        ];

        if ($images !== []) {
            $payload['images'] = $images;
        }

        $this->mapping[] = $payload;
    }

    protected function mapToolCall(ToolCallMessage $message): void
    {
        $contentBlocks = $message->getContent();
        $textContent = '';

        foreach ($contentBlocks as $block) {
            if ($block instanceof TextContentBlock) {
                $textContent .= $block->text;
            }
        }

        $payload = [
            'role' => $message->getRole(),
            'content' => $textContent,
        ];

        if (\array_key_exists('tool_calls', $message->jsonSerialize())) {
            $toolCalls = $message->jsonSerialize()['tool_calls'];
            $payload['tool_calls'] = \array_map(function (array $toolCall): array {
                if (empty($toolCall['function']['arguments'])) {
                    $toolCall['function']['arguments'] = new \stdClass();
                }
                return $toolCall;
            }, $toolCalls);
        }

        $this->mapping[] = $payload;
    }

    public function mapToolsResult(ToolResultMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            $this->mapping[] = [
                'role' => MessageRole::TOOL->value,
                'content' => $tool->getResult()
            ];
        }
    }
}
