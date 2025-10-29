<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\ContentBlocks\AudioContentBlock;
use NeuronAI\Chat\ContentBlocks\ContentBlock;
use NeuronAI\Chat\ContentBlocks\FileContentBlock;
use NeuronAI\Chat\ContentBlocks\ImageContentBlock;
use NeuronAI\Chat\ContentBlocks\TextContentBlock;
use NeuronAI\Chat\ContentBlocks\VideoContentBlock;
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
        $contentBlocks = $message->getContent();

        return [
            'role' => $message->getRole() === MessageRole::ASSISTANT->value ? MessageRole::MODEL->value : $message->getRole(),
            'parts' => \array_map($this->mapContentBlock(...), $contentBlocks)
        ];
    }

    protected function mapContentBlock(ContentBlock $block): array
    {
        return match ($block::class) {
            TextContentBlock::class => [
                'text' => $block->text,
            ],
            ImageContentBlock::class,
            FileContentBlock::class,
            AudioContentBlock::class,
            VideoContentBlock::class => $this->mapMediaBlock($block),
            default => throw new ProviderException('Unsupported content block type: '.$block::class),
        };
    }

    protected function mapMediaBlock(ImageContentBlock|FileContentBlock|AudioContentBlock|VideoContentBlock $block): array
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
        return [
            'role' => MessageRole::MODEL->value,
            'parts' => [
                ...\array_map(fn (ToolInterface $tool): array => [
                    'functionCall' => [
                        'name' => $tool->getName(),
                        'args' => $tool->getInputs() !== [] ? $tool->getInputs() : new \stdClass(),
                    ]
                ], $message->getTools())
            ]
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
