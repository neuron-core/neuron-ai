<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;
use stdClass;

use function array_key_exists;
use function array_map;

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

    public function mapMessage(Message $message): void
    {
        $contentBlocks = $message->getContentBlocks();
        $textContent = '';
        $images = [];

        foreach ($contentBlocks as $block) {
            if ($block instanceof TextContent) {
                $textContent .= $block->content;
            } elseif ($block instanceof ImageContent) {
                if ($block->sourceType === SourceType::URL) {
                    throw new ProviderException('Ollama supports only base64 image type.');
                }
                $images[] = $block->content;
            }
        }

        $payload = [
            'role' => $message->getRole(),
            'content' => $textContent,
        ];

        if ($images !== []) {
            $payload['images'] = $images;
        }

        $this->mapping[] = $payload;
    }

    protected function mapToolCall(ToolCallMessage $message): void
    {
        $payload = [
            'role' => $message->getRole(),
            'content' => $message->getContent(),
        ];

        if (array_key_exists('tool_calls', $message->jsonSerialize())) {
            $toolCalls = $message->jsonSerialize()['tool_calls'];
            $payload['tool_calls'] = array_map(function (array $toolCall): array {
                if (empty($toolCall['function']['arguments'])) {
                    $toolCall['function']['arguments'] = new stdClass();
                }
                return $toolCall;
            }, $toolCalls);
        }

        $this->mapping[] = $payload;
    }

    public function mapToolsResult(ToolResultMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            $this->mapping[] = [
                'role' => MessageRole::TOOL->value,
                'content' => $tool->getResult()
            ];
        }
    }
}
