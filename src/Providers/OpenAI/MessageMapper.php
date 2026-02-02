<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\ToolInterface;

use function array_filter;
use function array_is_list;
use function array_map;
use function array_merge;
use function json_encode;

class MessageMapper implements MessageMapperInterface
{
    protected array $mapping = [];

    public function map(array $messages): array
    {
        $this->mapping = [];

        foreach ($messages as $message) {
            $item = match ($message::class) {
                Message::class,
                UserMessage::class,
                AssistantMessage::class => $this->mapMessage($message),
                ToolCallMessage::class => $this->mapToolCall($message),
                ToolResultMessage::class => $this->mapToolsResult($message),
                default => throw new ProviderException('Unknown message type '.$message::class),
            };

            if (array_is_list($item)) {
                $this->mapping = array_merge($this->mapping, $item);
            } else {
                $this->mapping[] = $item;
            }
        }

        return $this->mapping;
    }

    protected function mapMessage(Message $message): array
    {
        return [
            'role' => $message->getRole(),
            'content' => $this->mapBlocks($message->getContentBlocks()),
        ];
    }

    protected function mapBlocks(array $blocks): array
    {
        return array_filter(array_map($this->mapContentBlock(...), $blocks));
    }

    protected function mapContentBlock(ContentBlockInterface $block): ?array
    {
        return match ($block::class) {
            TextContent::class => [
                'type' => 'text',
                'text' => $block->content,
            ],
            ImageContent::class => $this->mapImageBlock($block),
            FileContent::class => $this->mapFileBlock($block),
            default => null,
        };
    }

    protected function mapImageBlock(ImageContent $block): array
    {
        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => match ($block->sourceType) {
                    SourceType::URL, SourceType::ID => $block->content,
                    SourceType::BASE64 => 'data:'.$block->mediaType.';base64,'.$block->content,
                },
            ],
        ];
    }

    protected function mapFileBlock(FileContent $block): ?array
    {
        return match ($block->sourceType) {
            SourceType::BASE64 => [
                'type' => 'file',
                'file' => [
                    'filename' => $block->filename,
                    'file_data' => "data:{$block->mediaType};base64,{$block->content}",
                ]
            ],
            SourceType::ID => [
                'type' => 'file',
                'file' => [
                    'file_id' => $block->content,
                ]
            ],
            SourceType::URL => null
        };
    }

    protected function mapToolCall(ToolCallMessage $message): array
    {
        $item = [
            'role' => MessageRole::ASSISTANT,
            'tool_calls' => array_map(fn (ToolInterface $tool): array => [
                'id' => $tool->getCallId(),
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    ...($tool->getInputs() === [] ? [] : ['arguments' => json_encode($tool->getInputs())]),
                ],
            ], $message->getTools())
        ];

        $content = $this->mapBlocks($message->getContentBlocks());
        if ($content !== []) {
            $item['content'] = $content;
        }

        return $item;
    }

    protected function mapToolsResult(ToolResultMessage $message): array
    {
        return array_map(fn (ToolInterface $tool): array => [
            'role' => MessageRole::TOOL,
            'tool_call_id' => $tool->getCallId(),
            'content' => $tool->getResult()
        ], $message->getTools());
    }
}
