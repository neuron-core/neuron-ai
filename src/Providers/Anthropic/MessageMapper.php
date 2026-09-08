<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use stdClass;

use function array_filter;
use function array_map;
use function array_splice;
use function ksort;
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

    protected function mapMessageContent(Message $message, array $toolContents = []): array
    {
        $contents = $this->mapBlocks($message->getContentBlocks());
        $insertions = [];
        foreach ($message->getMetadata('anthropic_redacted_thinking') ?? [] as $index => $data) {
            $insertions[$index] = ['type' => 'redacted_thinking', 'data' => $data];
        }

        $toolPositions = $message->getMetadata('anthropic_tool_positions') ?? [];
        foreach ($toolContents as $index => $toolContent) {
            if (isset($toolPositions[$index])) {
                $insertions[$toolPositions[$index]] = $toolContent;
            } else {
                $contents[] = $toolContent;
            }
        }

        // Positions refer to the original response, including redacted blocks and tool calls.
        ksort($insertions);
        foreach ($insertions as $index => $content) {
            array_splice($contents, $index, 0, [$content]);
        }

        return $contents;
    }

    protected function mapMessage(Message $message): array
    {
        return [
            'role' => $message->getRole(),
            'content' => $this->mapMessageContent($message),
        ];
    }

    protected function mapBlocks(array $blocks): array
    {
        return array_values(array_filter(array_map($this->mapSingleBlock(...), $blocks)));
    }

    protected function mapSingleBlock(ContentBlockInterface $block): ?array
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
            default => null,
        };
    }

    protected function mapImageBlock(ImageContent $block): ?array
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
            SourceType::ID => [
                'type' => 'image',
                'source' => [
                    'type' => 'file',
                    'file_id' => $block->content,
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
            SourceType::ID => [
                'type' => 'document',
                'source' => [
                    'type' => 'file',
                    'file_id' => $block->content,
                ],
            ],
        };
    }

    protected function mapToolCall(ToolCallMessage $message): array
    {
        $parts = [];
        foreach ($message->getToolCalls() as $tool) {
            $parts[] = [
                'type' => 'tool_use',
                'id' => $tool->getCallId(),
                'name' => $tool->getName(),
                'input' => $tool->getInputs() ?: new stdClass(),
            ];
        }

        return [
            'role' => MessageRole::ASSISTANT,
            'content' => $this->mapMessageContent($message, $parts),
        ];
    }

    protected function mapToolsResult(ToolResultMessage $message): array
    {
        $parts = array_map(fn (ToolCall $tool): array => [
            'type' => 'tool_result',
            'tool_use_id' => $tool->getCallId(),
            'content' => ($result = $tool->getResult()) instanceof ToolOutput
                ? ($this->mapBlocks($result->getBlocks()) ?: $result->getText())
                : $result,
            ...($result instanceof ToolOutput && $result->isError() ? ['is_error' => true] : []),
        ], $message->getToolCalls());

        if ($contentBlocks = $message->getContentBlocks()) {
            $parts = [...$parts, ...$this->mapBlocks($contentBlocks)];
        }

        return [
            'role' => MessageRole::USER,
            'content' => array_values($parts),
        ];
    }
}
