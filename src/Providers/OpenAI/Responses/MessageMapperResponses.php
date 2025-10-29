<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use NeuronAI\Chat\ContentBlocks\FileContentBlock;
use NeuronAI\Chat\ContentBlocks\ImageContentBlock;
use NeuronAI\Chat\ContentBlocks\TextContentBlock;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;

class MessageMapperResponses implements MessageMapperInterface
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
        $contentBlocks = $message->getContent();

        // Map content blocks to the provider format
        $payload['content'] = [];
        foreach ($contentBlocks as $block) {
            $payload['content'][] = match ($block::class) {
                TextContentBlock::class => $this->mapTextBlock($block, $this->isUserMessage($message)),
                FileContentBlock::class => $this->mapFileBlock($block),
                ImageContentBlock::class => $this->mapImageBlock($block),
                default => throw new ProviderException('Unsupported content block type: '.$block::class),
            };
        }

        $this->mapping[] = $payload;
    }

    protected function mapTextBlock(TextContentBlock $block, bool $forUser): array
    {
        return [
            'type' => $forUser ? 'input_text' : 'output_text',
            'text' => $block->text,
        ];
    }

    protected function mapFileBlock(FileContentBlock $block): array
    {
        return [
            'type' => 'file',
            'file' => [
                'filename' => $block->filename ?? "attachment-".\uniqid().".pdf",
                'file_data' => "data:{$block->mediaType};base64,{$block->source}",
            ]
        ];
    }

    protected function mapImageBlock(ImageContentBlock $block): array
    {
        return [
            'type' => 'input_image',
            'image_url' => match ($block->sourceType) {
                SourceType::URL => $block->source,
                SourceType::BASE64 => 'data:'.$block->mediaType.';base64,'.$block->source,
            },
        ];
    }

    protected function isUserMessage(Message $message): bool
    {
        return $message instanceof UserMessage || $message->getRole() === MessageRole::USER->value;
    }

    protected function mapToolCall(ToolCallMessage $message): void
    {
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
