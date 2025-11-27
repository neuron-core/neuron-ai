<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

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
        $payload['role'] = $message->getRole();
        $contentBlocks = $message->getContentBlocks();

        // Map content blocks to the provider format
        $payload['content'] = $this->mapContentBlocks($contentBlocks, $this->isUserMessage($message));

        $this->mapping[] = $payload;
    }

    /**
     * @param ContentBlockInterface[] $blocks
     * @param bool $isUser
     * @return array
     * @throws ProviderException
     */
    protected function mapContentBlocks(array $blocks, bool $isUser): array
    {
        $blocks = \array_filter($blocks, fn (ContentBlockInterface $block): bool => !$block instanceof ReasoningContent);
        return \array_map(fn (ContentBlockInterface $block): array => match ($block::class) {
            TextContent::class => $this->mapTextBlock($block, $isUser),
            FileContent::class => $this->mapFileBlock($block),
            ImageContent::class => $this->mapImageBlock($block),
            default => throw new ProviderException('Unsupported content block type: '.$block::class),
        }, $blocks);
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
        return [
            'type' => 'file',
            'file' => [
                'filename' => $block->filename ?? "attachment-".\uniqid().".pdf",
                'file_data' => "data:{$block->mediaType};base64,{$block->content}",
            ]
        ];
    }

    protected function mapImageBlock(ImageContent $block): array
    {
        return [
            'type' => 'input_image',
            'image_url' => match ($block->sourceType) {
                SourceType::URL => $block->content,
                SourceType::BASE64 => 'data:'.$block->mediaType.';base64,'.$block->content,
            },
        ];
    }

    protected function isUserMessage(Message $message): bool
    {
        return $message instanceof UserMessage || $message->getRole() === MessageRole::USER->value;
    }

    /**
     * @throws ProviderException
     */
    protected function mapToolCall(ToolCallMessage $message): void
    {
        // Add content blocks if present
        if ($contentBlocks = $message->getContentBlocks()) {
            $this->mapping = \array_merge($this->mapping, $this->mapContentBlocks($contentBlocks, false));
        }

        // Add function call items
        foreach ($message->getTools() as $tool) {
            $inputs = $tool->getInputs();
            $this->mapping[] = [
                'type' => 'function_call',
                'name' => $tool->getName(),
                'arguments' => \json_encode($inputs !== [] ? $inputs : new \stdClass()),
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
