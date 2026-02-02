<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

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
use stdClass;

use function array_filter;
use function array_map;
use function array_merge;
use function json_encode;

class MessageMapper implements MessageMapperInterface
{
    protected array $mapping = [];

    /**
     * @throws ProviderException
     */
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
                default => throw new ProviderException('Unknown message type '.$message::class),
            };
        }

        return $this->mapping;
    }

    protected function mapMessage(Message $message): void
    {
        $this->mapping[] = [
            'role' => $message->getRole(),
            'content' => $this->mapBlocks($message->getContentBlocks(), $this->isUserMessage($message)),
        ];
    }

    /**
     * @param ContentBlockInterface[] $blocks
     */
    protected function mapBlocks(array $blocks, bool $isUser): array
    {
        return array_filter(array_map(
            fn (ContentBlockInterface $item): ?array => $this->mapContentBlock($item, $isUser),
            $blocks
        ));
    }

    protected function mapContentBlock(ContentBlockInterface $block, bool $isUser): ?array
    {
        return match ($block::class) {
            TextContent::class => $this->mapTextBlock($block, $isUser),
            FileContent::class => $this->mapFileBlock($block),
            ImageContent::class => $this->mapImageBlock($block),
            default => null
        };
    }

    protected function mapTextBlock(TextContent $block, bool $forUser): array
    {
        return [
            'type' => $forUser ? 'input_text' : 'output_text',
            'text' => $block->content,
        ];
    }

    protected function mapFileBlock(FileContent $block): array
    {
        return match ($block->sourceType) {
            SourceType::BASE64 => [
                'type' => 'input_file',
                'filename' => $block->filename,
                'file_data' => "data:{$block->mediaType};base64,{$block->content}",
            ],
            SourceType::URL => [
                'type' => 'input_file',
                'file_url' => $block->content,
            ],
            SourceType::ID => [
                'type' => 'input_file',
                'file_id' => $block->content,
            ],
        };
    }

    protected function mapImageBlock(ImageContent $block): array
    {
        return match ($block->sourceType) {
            SourceType::URL => [
                'type' => 'input_image',
                'image_url' => $block->content,
            ],
            SourceType::BASE64 => [
                'type' => 'input_image',
                'image_url' => 'data:'.$block->mediaType.';base64,'.$block->content,
            ],
            SourceType::ID => [
                'type' => 'input_image',
                'file_id' => $block->content,
            ]
        };
    }

    protected function isUserMessage(Message $message): bool
    {
        return $message instanceof UserMessage || $message->getRole() === MessageRole::USER->value;
    }

    protected function mapToolCall(ToolCallMessage $message): void
    {
        // Add content blocks if present
        if ($contentBlocks = $message->getContentBlocks()) {
            $this->mapping = array_merge($this->mapping, $this->mapBlocks($contentBlocks, false));
        }

        // Add function call items
        foreach ($message->getTools() as $tool) {
            $this->mapping[] = [
                'type' => 'function_call',
                'name' => $tool->getName(),
                'arguments' => json_encode($tool->getInputs() ?: new stdClass()),
                'call_id' => $tool->getCallId(),
            ];
        }
    }

    protected function mapToolsResult(ToolResultMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            $this->mapping[] = [
                'type' => 'function_call_output',
                'call_id' => $tool->getCallId(),
                'output' => $tool->getResult(),
            ];
        }
    }
}
