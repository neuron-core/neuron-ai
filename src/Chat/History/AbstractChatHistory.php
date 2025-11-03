<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Enums\ContentBlockType;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\FileContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tools\Tool;

abstract class AbstractChatHistory implements ChatHistoryInterface
{
    /**
     * @var Message[]
     */
    protected array $history = [];

    public function __construct(
        protected int $contextWindow = 50000,
        protected TokenCounterInterface $tokenCounter = new TokenCounter()
    ) {
    }

    /**
     * @param Message[] $messages
     */
    abstract public function setMessages(array $messages): ChatHistoryInterface;

    abstract protected function clear(): ChatHistoryInterface;

    protected function onNewMessage(Message $message): void
    {
        // Handle single message addition
    }

    protected function onTrimHistory(int $index): void
    {
        // When the trim is triggered, the messages in the position from zero to the index are removed.
    }

    public function addMessage(Message $message): ChatHistoryInterface
    {
        $this->history[] = $message;

        $this->onNewMessage($message);

        $skipIndex = $this->trimHistory();

        if ($skipIndex > 0) {
            $this->onTrimHistory($skipIndex);
        }

        $this->setMessages($this->history);

        return $this;
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
        $message = \end($this->history);

        if ($message === false) {
            throw new ChatHistoryException('No messages in the chat history. It may have been filled with too large a message.');
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
        return $this->tokenCounter->count($this->history);
    }

    /**
     * @return int The index of the first element to retain (keeping most recent messages) - 0 Skip no messages (include all) - count($this->history): Skip all messages (include none)
     */
    protected function trimHistory(): int
    {
        if ($this->history === []) {
            return 0;
        }

        $tokenCount = $this->tokenCounter->count($this->history);

        // Early exit if all messages fit within the token limit
        if ($tokenCount <= $this->contextWindow) {
            $this->ensureValidMessageSequence();
            return 0;
        }

        // Binary search to find how many messages to skip from the beginning
        $skipFrom = $this->findMaxFittingMessages();

        $this->history = \array_slice($this->history, $skipFrom);

        // Ensure valid message sequence
        $this->ensureValidMessageSequence();

        return $skipFrom;
    }

    /**
     * Binary search to find the maximum number of messages that fit within the token limit.
     *
     * @return int The index of the first element to retain (keeping most recent messages) - 0 Skip no messages (include all) - count($this->history): Skip all messages (include none)
     */
    private function findMaxFittingMessages(): int
    {
        $totalMessages = \count($this->history);
        $left = 0;
        $right = $totalMessages;

        while ($left < $right) {
            $mid = \intval(($left + $right) / 2);
            $subset = \array_slice($this->history, $mid);

            if ($this->tokenCounter->count($subset) <= $this->contextWindow) {
                // Fits! Try including more messages (skip fewer)
                $right = $mid;
            } else {
                // Doesn't fit! Need to skip more messages
                $left = $mid + 1;
            }
        }

        return $left;
    }

    /**
     * Ensures the message list:
     * 1. Starts with a UserMessage
     * 2. Ends with an AssistantMessage
     * 3. Maintains tool call/result pairs
     */
    protected function ensureValidMessageSequence(): void
    {
        // Ensure it starts with a UserMessage
        $this->ensureStartsWithUser();

        // Ensure it ends with an AssistantMessage
        $this->ensureValidAlternation();
    }

    /**
     * Ensures the message list starts with a UserMessage.
     */
    protected function ensureStartsWithUser(): void
    {
        // Find the first UserMessage
        $firstUserIndex = null;
        foreach ($this->history as $index => $message) {
            if ($message->getRole() === MessageRole::USER->value) {
                $firstUserIndex = $index;
                break;
            }
        }

        if ($firstUserIndex === null) {
            // No UserMessage found
            $this->history = [];
            return;
        }

        if ($firstUserIndex === 0) {
            return;
        }

        if ($firstUserIndex > 0) {
            // Remove messages before the first user message
            $this->history = \array_slice($this->history, $firstUserIndex);
        }
    }

    /**
     * Ensures valid alternation between user and assistant messages.
     */
    protected function ensureValidAlternation(): void
    {
        $result = [];
        $expectingRole = [MessageRole::USER->value]; // Should start with user

        foreach ($this->history as $message) {
            $messageRole = $message->getRole();

            // Tool result messages have a special case - they're user messages
            // but can only follow tool call messages (assistant)
            // This is valid after a ToolCallMessage
            if ($message instanceof ToolResultMessage && ($result !== [] && $result[\count($result) - 1] instanceof ToolCallMessage)) {
                $result[] = $message;
                // After the tool result, we expect assistant again
                $expectingRole = [MessageRole::ASSISTANT->value, MessageRole::MODEL->value];
                continue;
            }

            // Check if this message has the expected role
            if (\in_array($messageRole, $expectingRole, true)) {
                $result[] = $message;
                // Toggle the expected role
                $expectingRole = ($expectingRole === [MessageRole::USER->value])
                    ? [MessageRole::ASSISTANT->value, MessageRole::MODEL->value]
                    : [MessageRole::ASSISTANT->value, MessageRole::USER->value];
            }
            // If not the expected role, we have an invalid alternation
            // Skip this message to maintain a valid sequence
        }

        $this->history = $result;
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
        return \array_map(fn (array $message): Message => match ($message['type'] ?? null) {
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
        $tools = \array_map(fn (array $tool) => Tool::make($tool['name'], $tool['description'])
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
        $tools = \array_map(fn (array $tool) => Tool::make($tool['name'], $tool['description'])
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
     * @return string|ContentBlock|ContentBlock[]|null
     */
    protected function deserializeContent(mixed $content): string|ContentBlock|array|null
    {
        if ($content === null) {
            return null;
        }

        // Legacy format: simple string - convert to TextContent for migration
        if (\is_string($content)) {
            return new TextContent($content);
        }

        // New format: array of content blocks
        if (\is_array($content)) {
            // Check if it's an array of content blocks (has 'type' key in first element)
            if (isset($content[0]['type'])) {
                return \array_map($this->deserializeContentBlock(...), $content);
            }

            // Single block structure (has 'type' key at root level)
            if (isset($content['type'])) {
                return $this->deserializeContentBlock($content);
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
    protected function deserializeContentBlock(array $block): ContentBlock
    {
        $type = ContentBlockType::from($block['type']);

        return match ($type) {
            ContentBlockType::TEXT => new TextContent(
                text: $block['text']
            ),
            ContentBlockType::IMAGE => new ImageContent(
                source: $block['source'],
                sourceType: SourceType::from($block['source_type']),
                mediaType: $block['media_type'] ?? null
            ),
            ContentBlockType::FILE => new FileContentBlock(
                source: $block['source'],
                sourceType: SourceType::from($block['source_type']),
                mediaType: $block['media_type'] ?? null,
                filename: $block['filename'] ?? null
            ),
            ContentBlockType::AUDIO => new AudioContent(
                source: $block['source'],
                sourceType: SourceType::from($block['source_type']),
                mediaType: $block['media_type'] ?? null
            ),
            ContentBlockType::VIDEO => new VideoContent(
                source: $block['source'],
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
            if ($key === 'attachments') {
                // Attachments are now part of content blocks, skip this key
                continue;
            }
            $item->addMetadata($key, $value);
        }
    }
}
