<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
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
            'role' => $message->getRole() === MessageRole::ASSISTANT->value ? MessageRole::MODEL : $message->getRole(),
            'parts' => \array_map($this->mapContentBlock(...), $contentBlocks)
        ];
    }

    protected function mapContentBlock(ContentBlock $block): array
    {
        return match ($block::class) {
            TextContent::class => [
                'text' => $block->text,
            ],
            ReasoningContent::class => [
                'thought' => true,
                'text' => $block->text,
            ],
            ImageContent::class,
            FileContent::class,
            AudioContent::class,
            VideoContent::class => $this->mapMediaBlock($block),
            default => throw new ProviderException('Unsupported content block type: '.$block::class),
        };
    }

    protected function mapMediaBlock(ImageContent|FileContent|AudioContent|VideoContent $block): array
    {
        return match ($block->sourceType) {
            SourceType::URL => [
                'file_data' => [
                    'file_uri' => $block->source,
                    'mime_type' => $block->mediaType,
                ],
            ],
            SourceType::BASE64 => [
                'inline_data' => [
                    'data' => $block->source,
                    'mime_type' => $block->mediaType,
                ]
            ]
        };
    }

    protected function mapToolCall(ToolCallMessage $message): array
    {
        $parts = [];

        if ($contentBlocks = $message->getContentBlocks()) {
            $parts = \array_map($this->mapContentBlock(...), $contentBlocks);
        }

        foreach ($message->getTools() as $tool) {
            $parts[] = [
                'functionCall' => [
                    'name' => $tool->getName(),
                    'args' => $tool->getInputs() !== [] ? $tool->getInputs() : new \stdClass(),
                ]
            ];
        }

        return [
            'role' => MessageRole::MODEL,
            'parts' => $parts
        ];
    }

    protected function mapToolsResult(ToolResultMessage $message): array
    {
        $parts = \array_map(fn (ToolInterface $tool): array => [
            'functionResponse' => [
                'name' => $tool->getName(),
                'response' => [
                    'name' => $tool->getName(),
                    'content' => $tool->getResult(),
                ],
            ],
        ], $message->getTools());

        if ($contentBlocks = $message->getContentBlocks()) {
            $parts = [...$parts, ...\array_map($this->mapContentBlock(...), $contentBlocks)];
        }

        return [
            'role' => MessageRole::USER,
            'parts' => \array_values($parts),
        ];
    }
}
