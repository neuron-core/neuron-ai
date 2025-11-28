<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
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
use stdClass;

use function array_map;
use function array_values;

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
            'content' => array_map($this->mapContentBlock(...), $contentBlocks)
        ];
    }

    protected function mapContentBlock(ContentBlockInterface $block): array
    {
        return match ($block::class) {
            TextContent::class => [
                'type' => 'text',
                'text' => $block->content,
            ],
            ReasoningContent::class => [
                'type' => 'thinking',
                'thinking' => $block->content,
                'signature' => $block->id,
            ],
            ImageContent::class => $this->mapImageBlock($block),
            FileContent::class => $this->mapFileBlock($block),
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
                    'url' => $block->content,
                ],
            ],
            SourceType::BASE64 => [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $block->mediaType,
                    'data' => $block->content,
                ],
            ],
        };
    }

    protected function mapFileBlock(FileContent $block): array
    {
        return match ($block->sourceType) {
            SourceType::URL => [
                'type' => 'document',
                'source' => [
                    'type' => 'url',
                    'url' => $block->content,
                ],
            ],
            SourceType::BASE64 => [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $block->mediaType,
                    'data' => $block->content,
                ],
            ],
        };
    }

    protected function mapToolCall(ToolCallMessage $message): array
    {
        $parts = [];

        // Add text content if present
        if ($contentBlocks = $message->getContentBlocks()) {
            $parts = array_map($this->mapContentBlock(...), $contentBlocks);
        }

        // Add tool call blocks from the tool array
        foreach ($message->getTools() as $tool) {
            $parts[] = [
                'type' => 'tool_use',
                'id' => $tool->getCallId(),
                'name' => $tool->getName(),
                'input' => $tool->getInputs() ?: new stdClass(),
            ];
        }

        return [
            'role' => MessageRole::ASSISTANT,
            'content' => $parts,
        ];
    }

    protected function mapToolsResult(ToolResultMessage $message): array
    {
        $parts = array_map(fn (ToolInterface $tool): array => [
            'type' => 'tool_result',
            'tool_use_id' => $tool->getCallId(),
            'content' => $tool->getResult(),
        ], $message->getTools());

        if ($contentBlocks = $message->getContentBlocks()) {
            $parts = [...$parts, ...array_map($this->mapContentBlock(...), $contentBlocks)];
        }

        return [
            'role' => MessageRole::USER,
            'content' => array_values($parts),
        ];
    }
}
