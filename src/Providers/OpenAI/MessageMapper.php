<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
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

use function array_map;
use function uniqid;

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

    protected function mapMessage(Message $message): void
    {
        $contentBlocks = $message->getContentBlocks();

        $this->mapping[] = [
            'role' => $message->getRole(),
            'content' => array_map($this->mapContentBlock(...), $contentBlocks)
        ];
    }

    /**
     * @throws ProviderException
     */
    protected function mapContentBlock(ContentBlockInterface $block): array
    {
        return match ($block::class) {
            TextContent::class => [
                'type' => 'text',
                'text' => $block->content,
            ],
            ImageContent::class => $this->mapImageBlock($block),
            FileContent::class => $this->mapFileBlock($block),
            default => throw new ProviderException('Unsupported content block type: '.$block::class),
        };
    }

    protected function mapImageBlock(ImageContent $block): array
    {
        $url = match ($block->sourceType) {
            SourceType::URL => $block->content,
            SourceType::BASE64 => 'data:'.$block->mediaType.';base64,'.$block->content,
        };

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => $url,
            ],
        ];
    }

    protected function mapFileBlock(FileContent $block): array
    {
        if ($block->sourceType === SourceType::URL) {
            throw new ProviderException('This provider does not support URL document attachments.');
        }

        return [
            'type' => 'file',
            'file' => [
                'filename' => $block->filename ?? "attachment-".uniqid().".pdf",
                'file_data' => "data:{$block->mediaType};base64,{$block->content}",
            ]
        ];
    }

    protected function mapToolCall(ToolCallMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            $this->mapping[] = [
                'role' => MessageRole::ASSISTANT,
                'tool_calls' => [
                    [
                        'id' => $tool->getCallId(),
                        'type' => 'function',
                        'function' => [
                            'name' => $tool->getName(),
                            'arguments' => $tool->getInputs(),
                        ],
                    ]
                ]
            ];
        }
    }

    protected function mapToolsResult(ToolResultMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            $this->mapping[] = [
                'role' => MessageRole::TOOL,
                'tool_call_id' => $tool->getCallId(),
                'content' => $tool->getResult()
            ];
        }
    }
}
