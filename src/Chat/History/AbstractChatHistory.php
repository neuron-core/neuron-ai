<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Enums\ContentBlockType;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tools\Tool;

use function array_map;
use function count;
use function end;
use function is_array;
use function is_string;
use function json_decode;

abstract class AbstractChatHistory implements ChatHistoryInterface
{
    /**
     * @var Message[]
     */
    protected array $history = [];

    public function __construct(
        protected int $contextWindow = 50000,
        protected HistoryTrimmerInterface $trimmer = new HistoryTrimmer()
    ) {
    }

    /**
     * @param Message[] $messages
     */
    protected function setMessages(array $messages): void
    {
        // Handle saving the entire history at once.
    }

    protected function onNewMessage(Message $message): void
    {
        // Handle single message addition.
    }

    protected function onTrimHistory(int $index): void
    {
        // When the trim is triggered, the messages in the position from zero to $index must be removed.
    }

    protected function clear(): void
    {
        // Remove all messages.
    }

    public function addMessage(Message $message): ChatHistoryInterface
    {
        $this->history[] = $message;

        $this->trimHistory();

        $this->onNewMessage($message);

        $this->setMessages($this->history);

        return $this;
    }

    protected function trimHistory(): void
    {
        $trimmed = $this->trimmer->trim($this->history, $this->contextWindow);

        $skipIndex = count($this->history) - count($trimmed);

        if ($skipIndex > 0) {
            $this->history = $trimmed;
            $this->onTrimHistory($skipIndex);
        }
    }

    public function getMessages(): array
    {
        return $this->history;
    }

    /**
     * @throws ChatHistoryException
     */
    public function getLastMessage(): Message
    {
        $message = end($this->history);

        if ($message === false) {
            throw new ChatHistoryException('No messages in the chat history. It may have been filled with too large single message.');
        }

        return $message;
    }

    public function flushAll(): ChatHistoryInterface
    {
        $this->clear();
        $this->history = [];
        return $this;
    }

    public function calculateTotalUsage(): int
    {
        return $this->trimmer->getTotalTokens();
    }

    /**
     * @return array<int, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->getMessages();
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return  Message[]
     */
    protected function deserializeMessages(array $messages): array
    {
        return array_map(fn (array $message): Message => match ($message['type'] ?? null) {
            'tool_call' => $this->deserializeToolCall($message),
            'tool_call_result' => $this->deserializeToolCallResult($message),
            default => $this->deserializeMessage($message),
        }, $messages);
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function deserializeMessage(array $message): Message
    {
        $messageRole = MessageRole::from($message['role']);
        $messageContent = $this->deserializeContent($message['content'] ?? null);

        $item = match ($messageRole) {
            MessageRole::ASSISTANT => new AssistantMessage($messageContent),
            MessageRole::USER => new UserMessage($messageContent),
            default => new Message($messageRole, $messageContent)
        };

        $this->deserializeMeta($message, $item);

        return $item;
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function deserializeToolCall(array $message): ToolCallMessage
    {
        $tools = array_map(fn (array $tool) => Tool::make($tool['name'], $tool['description'])
            ->setInputs($tool['inputs'])
            ->setCallId($tool['callId'] ?? null), $message['tools']);

        $item = new ToolCallMessage(tools: $tools);

        $this->deserializeMeta($message, $item);

        return $item;
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function deserializeToolCallResult(array $message): ToolResultMessage
    {
        $tools = array_map(fn (array $tool) => Tool::make($tool['name'], $tool['description'])
            ->setInputs($tool['inputs'])
            ->setCallId($tool['callId'])
            ->setResult($tool['result']), $message['tools']);

        return new ToolResultMessage($tools);
    }

    /**
     * Deserialize content from storage format to ContentBlock array.
     *
     * Handles both legacy string format and new content block array format.
     * Legacy formats are automatically converted to ContentBlocks for migration.
     *
     * @return string|ContentBlockInterface|ContentBlockInterface[]|null
     */
    protected function deserializeContent(mixed $content): string|ContentBlockInterface|array|null
    {
        if ($content === null) {
            return null;
        }

        // Legacy format: simple string - convert to TextContent for migration
        if (is_string($content)) {
            if ($json = json_decode($content, true)) {
                return $this->deserializeContent($json);
            }
            return new TextContent($content);
        }

        // New format: array of content blocks
        if (is_array($content)) {
            // Check if it's an array of content blocks (has 'type' key in first element)
            if (isset($content[0]['type'])) {
                return array_map($this->deserializeContentBlock(...), $content);
            }

            // Empty array
            if ($content === []) {
                return null;
            }
        }

        // Fallback: treat as string and convert to TextContent
        return new TextContent((string) $content);
    }

    /**
     * Deserialize a single content block from array format.
     *
     * @param array<string, mixed> $block
     */
    protected function deserializeContentBlock(array $block): ContentBlockInterface
    {
        $type = ContentBlockType::from($block['type']);

        return match ($type) {
            ContentBlockType::TEXT => new TextContent(
                content: $block['content']
            ),
            ContentBlockType::REASONING => new ReasoningContent(
                content: $block['content'],
                id: $block['id'] ?? null
            ),
            ContentBlockType::IMAGE => new ImageContent(
                content: $block['content'],
                sourceType: SourceType::from($block['source_type']),
                mediaType: $block['media_type'] ?? null
            ),
            ContentBlockType::FILE => new FileContent(
                content: $block['content'],
                sourceType: SourceType::from($block['source_type']),
                mediaType: $block['media_type'] ?? null,
                filename: $block['filename'] ?? null
            ),
            ContentBlockType::AUDIO => new AudioContent(
                content: $block['content'],
                sourceType: SourceType::from($block['source_type']),
                mediaType: $block['media_type'] ?? null
            ),
            ContentBlockType::VIDEO => new VideoContent(
                content: $block['content'],
                sourceType: SourceType::from($block['source_type']),
                mediaType: $block['media_type'] ?? null
            ),
        };
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function deserializeMeta(array $message, Message $item): void
    {
        foreach ($message as $key => $value) {
            if ($key === 'role') {
                continue;
            }
            if ($key === 'content') {
                continue;
            }
            if ($key === 'usage') {
                $item->setUsage(
                    new Usage($message['usage']['input_tokens'], $message['usage']['output_tokens'])
                );
                continue;
            }
            if ($key === 'citations' && is_array($value)) {
                // Deserialize citations from array back to Citation objects
                $citations = array_map(
                    Citation::fromArray(...),
                    $value
                );
                $item->addMetadata($key, $citations);
                continue;
            }
            $item->addMetadata($key, $value);
        }
    }
}
