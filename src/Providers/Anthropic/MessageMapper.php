<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\FileContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
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
            TextContent::class => [
                'type' => 'text',
                'text' => $block->text,
            ],
            ReasoningContent::class => [
                'type' => 'thinking',
                'thinking' => $block->text,
                'signature' => $block->id,
            ],
            ImageContent::class => $this->mapImageBlock($block),
            FileContentBlock::class => $this->mapFileBlock($block),
            default => throw new ProviderException('Unsupported content block type: '.$block::class),
        };
    }

    protected function mapImageBlock(ImageContent $block): array
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

    protected function mapToolCall(ToolCallMessage $message): array
    {
        $parts = [];
        // Add text content if present
        $content = $message->getContent();

        // Add text content if present
        if ($content !== '' && $content !== '0') {
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
                'input' => $tool->getInputs() ?: new \stdClass(),
            ];
        }

        return [
            'role' => MessageRole::ASSISTANT->value,
            'content' => $parts,
        ];
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
