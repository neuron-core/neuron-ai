<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Attachments\Document;
use NeuronAI\Chat\Attachments\Image;
use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Chat\Enums\AttachmentType;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tools\Tool;

use function array_map;
use function array_slice;
use function count;
use function end;
use function in_array;

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
        $message = end($this->history);

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

        $this->history = array_slice($this->history, $skipFrom);

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
        $totalMessages = count($this->history);
        $left = 0;
        $right = $totalMessages;

        while ($left < $right) {
            $mid = (int) (($left + $right) / 2);
            $subset = array_slice($this->history, $mid);

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
     * Ensures the message list is valid for the model:
     * 1. Leading tool_call / tool_call_result messages are removed.
     * 2. The first message is a "real" message (user/assistant/model).
     * 3. Alternation between user and assistant/model roles is preserved,
     *    and tool_call/tool_call_result pairs are kept valid.
     */
    protected function ensureValidMessageSequence(): void
    {
        // Drop leading tool_call / tool_call_result messages
        $this->dropLeadingToolMessages();

        if ($this->history === []) {
            return;
        }

        // Ensure the first message is a real chat message (USER / ASSISTANT / MODEL)
        $this->ensureStartsWithUser();

        if ($this->history === []) {
            return;
        }

        // Normalize role alternation and keep tool_call/tool_call_result pairs
        $this->ensureValidAlternation();
    }

    /**
     * Drops all leading ToolCallMessage / ToolCallResultMessage from the history.
     */
    protected function dropLeadingToolMessages(): void
    {
        if ($this->history === []) {
            return;
        }

        $start = 0;

        foreach ($this->history as $index => $message) {
            if ($message instanceof ToolCallMessage || $message instanceof ToolCallResultMessage) {
                $start = $index + 1;
                continue;
            }

            // First non-tool message reached, stop advancing
            break;
        }

        if ($start > 0) {
            $this->history = array_slice($this->history, $start);
        }
    }

    /**
     * Ensures the history starts with a "real" chat message:
     * USER, ASSISTANT or MODEL. If none exists, history is cleared.
     */
    protected function ensureStartsWithUser(): void
    {
        if ($this->history === []) {
            return;
        }

        $firstIndex = null;

        foreach ($this->history as $index => $message) {
            $role = $message->getRole();

            if (in_array($role, [
                MessageRole::USER->value,
                MessageRole::DEVELOPER->value,
            ], true)) {
                $firstIndex = $index;
                break;
            }
        }

        // No real chat message found – clear the history
        if ($firstIndex === null) {
            $this->history = [];
            return;
        }

        if ($firstIndex > 0) {
            $this->history = array_slice($this->history, $firstIndex);
        }
    }

    /**
     * Ensures valid alternation between user and assistant/model messages,
     * while preserving valid tool_call/tool_call_result pairs.
     */
    protected function ensureValidAlternation(): void
    {
        if ($this->history === []) {
            return;
        }

        $result = [];

        // First message is already guaranteed to be USER / ASSISTANT / MODEL
        $firstRole = $this->history[0]->getRole();

        $expectingRole = match ($firstRole) {
            MessageRole::ASSISTANT->value,
            MessageRole::MODEL->value => [MessageRole::ASSISTANT->value, MessageRole::MODEL->value],
            default => [MessageRole::USER->value],
        };

        foreach ($this->history as $message) {
            $messageRole = $message->getRole();

            // Tool result messages have a special case - they're user messages
            // but can only follow tool call messages (assistant)
            // This is valid after a ToolCallMessage
            if (
                $message instanceof ToolCallResultMessage
                && $result !== []
                && $result[count($result) - 1] instanceof ToolCallMessage
            ) {
                $result[] = $message;
                // After the tool result, we expect assistant again
                $expectingRole = [MessageRole::ASSISTANT->value, MessageRole::MODEL->value];
                continue;
            }

            // Check if this message has the expected role
            if (in_array($messageRole, $expectingRole, true)) {
                $result[] = $message;

                $expectingRole = $messageRole === MessageRole::USER->value
                    ? [MessageRole::ASSISTANT->value, MessageRole::MODEL->value]
                    : [MessageRole::USER->value];
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
     * @param array<string, mixed> $messages
     * @return  Message[]
     */
    protected function deserializeMessages(array $messages): array
    {
        return array_map(
            fn (array $message): Message => match ($message['type'] ?? null) {
                'tool_call' => $this->deserializeToolCall($message),
                'tool_call_result' => $this->deserializeToolCallResult($message),
                default => $this->deserializeMessage($message),
            },
            $messages
        );
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function deserializeMessage(array $message): Message
    {
        $messageRole = MessageRole::from($message['role']);
        $messageContent = $message['content'] ?? null;

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
        $tools = array_map(
            fn (array $tool) => Tool::make($tool['name'], $tool['description'])
                ->setInputs($tool['inputs'])
                ->setCallId($tool['callId'] ?? null),
            $message['tools']
        );

        $item = new ToolCallMessage($message['content'], $tools);

        $this->deserializeMeta($message, $item);

        return $item;
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function deserializeToolCallResult(array $message): ToolCallResultMessage
    {
        $tools = array_map(
            fn (array $tool) => Tool::make($tool['name'], $tool['description'])
                ->setInputs($tool['inputs'])
                ->setCallId($tool['callId'])
                ->setResult($tool['result']),
            $message['tools']
        );

        return new ToolCallResultMessage($tools);
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
                foreach ($message['attachments'] as $attachment) {
                    switch (AttachmentType::from($attachment['type'])) {
                        case AttachmentType::IMAGE:
                            $item->addAttachment(
                                new Image(
                                    $attachment['content'],
                                    AttachmentContentType::from($attachment['content_type']),
                                    $attachment['media_type'] ?? null
                                )
                            );
                            break;
                        case AttachmentType::DOCUMENT:
                            $item->addAttachment(
                                new Document(
                                    $attachment['content'],
                                    AttachmentContentType::from($attachment['content_type']),
                                    $attachment['media_type'] ?? null
                                )
                            );
                            break;
                    }

                }
                continue;
            }
            $item->addMetadata($key, $value);
        }
    }

    public function setTokenCounter(TokenCounterInterface $tokenCounter): ChatHistoryInterface
    {
        $this->tokenCounter = $tokenCounter;
        return $this;
    }
}
