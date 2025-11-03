<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\FileContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
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
            'role' => $message->getRole() === MessageRole::ASSISTANT->value ? MessageRole::MODEL->value : $message->getRole(),
            'parts' => \array_map($this->mapContentBlock(...), $contentBlocks)
        ];
    }

    protected function mapContentBlock(ContentBlock $block): array
    {
        return match ($block::class) {
            TextContent::class => [
                'text' => $block->text,
            ],
            ImageContent::class,
            FileContentBlock::class,
            AudioContent::class,
            VideoContent::class => $this->mapMediaBlock($block),
            default => throw new ProviderException('Unsupported content block type: '.$block::class),
        };
    }

    protected function mapMediaBlock(ImageContent|FileContentBlock|AudioContent|VideoContent $block): array
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
        $content = $message->getContent();

        if ($content !== '' && $content !== '0') {
            $parts[] = ['text' => $content];
        }

        // Add function calls
        $parts = [
            ...$parts,
            ...\array_map(fn (ToolInterface $tool): array => [
                'functionCall' => [
                    'name' => $tool->getName(),
                    'args' => $tool->getInputs() !== [] ? $tool->getInputs() : new \stdClass(),
                ]
            ], $message->getTools())
        ];

        return [
            'role' => MessageRole::MODEL->value,
            'parts' => $parts
        ];
    }

    protected function mapToolsResult(ToolResultMessage $message): array
    {
        return [
            'role' => MessageRole::USER->value,
            'parts' => \array_map(fn (ToolInterface $tool): array => [
                'functionResponse' => [
                    'name' => $tool->getName(),
                    'response' => [
                        'name' => $tool->getName(),
                        'content' => $tool->getResult(),
                    ],
                ],
            ], $message->getTools()),
        ];
    }
}
