<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\ContentBlocks\ContentBlock;
use NeuronAI\Chat\ContentBlocks\FileContentBlock;
use NeuronAI\Chat\ContentBlocks\ImageContentBlock;
use NeuronAI\Chat\ContentBlocks\TextContentBlock;
use NeuronAI\Chat\ContentBlocks\ToolResultContentBlock;
use NeuronAI\Chat\ContentBlocks\ToolUseContentBlock;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
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
                ToolResultMessage::class => $this->mapToolsResult($message),
                default => throw new ProviderException('Could not map message type '.$message::class),
            };
        }

        return $mapping;
    }

    protected function mapMessage(Message $message): array
    {
        $contentBlocks = $message->getContentBlocks();

        return [
            'role' => $message->getRole(),
            'content' => \array_map($this->mapContentBlock(...), $contentBlocks)
        ];
    }

    protected function mapContentBlock(ContentBlock $block): array
    {
        return match ($block::class) {
            TextContentBlock::class => [
                'type' => 'text',
                'text' => $block->text,
            ],
            ImageContentBlock::class => $this->mapImageBlock($block),
            FileContentBlock::class => $this->mapFileBlock($block),
            ToolUseContentBlock::class => $this->mapToolUseBlock($block),
            ToolResultContentBlock::class => $this->mapToolResultBlock($block),
            default => throw new ProviderException('Unsupported content block type: '.$block::class),
        };
    }

    protected function mapImageBlock(ImageContentBlock $block): array
    {
        return match ($block->sourceType) {
            SourceType::URL => [
                'type' => 'image',
                'source' => [
                    'type' => 'url',
                    'url' => $block->source,
                ],
            ],
            SourceType::BASE64 => [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $block->mediaType,
                    'data' => $block->source,
                ],
            ],
        };
    }

    protected function mapFileBlock(FileContentBlock $block): array
    {
        return match ($block->sourceType) {
            SourceType::URL => [
                'type' => 'document',
                'source' => [
                    'type' => 'url',
                    'url' => $block->source,
                ],
            ],
            SourceType::BASE64 => [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $block->mediaType,
                    'data' => $block->source,
                ],
            ],
        };
    }

    protected function mapToolUseBlock(ToolUseContentBlock $block): array
    {
        return [
            'type' => 'tool_use',
            'id' => $block->id,
            'name' => $block->name,
            'input' => $block->input,
        ];
    }

    protected function mapToolResultBlock(ToolResultContentBlock $block): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $block->toolUseId,
            'content' => $block->content,
            'is_error' => $block->isError,
        ];
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

    protected function mapToolsResult(ToolResultMessage $message): array
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
