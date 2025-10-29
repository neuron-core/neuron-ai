<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

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

    /**
     * @throws ProviderException
     */
    protected function mapMessage(Message $message): void
    {
        $contentBlocks = $message->getContent();

        $this->mapping[] = [
            'role' => $message->getRole(),
            'content' => \array_map($this->mapContentBlock(...), $contentBlocks)
        ];
    }

    /**
     * @throws ProviderException
     */
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
        $url = match ($block->sourceType) {
            SourceType::URL => $block->source,
            SourceType::BASE64 => 'data:'.$block->mediaType.';base64,'.$block->source,
        };

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => $url,
            ],
        ];
    }

    protected function mapFileBlock(FileContentBlock $block): array
    {
        if ($block->sourceType === SourceType::URL) {
            throw new ProviderException('This provider does not support URL document attachments.');
        }

        return [
            'type' => 'file',
            'file' => [
                'filename' => $block->filename ?? "attachment-".\uniqid().".pdf",
                'file_data' => "data:{$block->mediaType};base64,{$block->source}",
            ]
        ];
    }

    protected function mapToolUseBlock(ToolUseContentBlock $block): array
    {
        return [
            'type' => 'function',
            'id' => $block->id,
            'function' => [
                'name' => $block->name,
                'arguments' => \json_encode($block->input),
            ],
        ];
    }

    protected function mapToolResultBlock(ToolResultContentBlock $block): array
    {
        return [
            'type' => 'tool',
            'tool_call_id' => $block->toolUseId,
            'content' => $block->content,
        ];
    }

    /**
     * @throws ProviderException
     */
    protected function mapToolCall(ToolCallMessage $message): void
    {
        $contentBlocks = $message->getContent();

        $this->mapping[] = [
            'role' => $message->getRole(),
            'content' => \array_map($this->mapContentBlock(...), $contentBlocks)
        ];
    }

    protected function mapToolsResult(ToolResultMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            $this->mapping[] = [
                'role' => MessageRole::TOOL->value,
                'tool_call_id' => $tool->getCallId(),
                'content' => $tool->getResult()
            ];
        }
    }
}
