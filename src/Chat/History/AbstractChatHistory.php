<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Enums\ContentBlockType;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;

use function array_map;
use function count;
use function end;
use function is_array;
use function is_string;
use function json_decode;

/**
 * The history is append-only (ADR 0006): addMessage() always appends. Subclasses
 * persist through one hook per primitive mutation — append (onNewMessage), head-trim
 * (onTrimHistory), clear (clear) — or ignore the granular hooks and rewrite the whole
 * state via setMessages(). All hooks default to no-ops; row-per-message backends
 * implement the granular set, whole-state backends implement setMessages() only.
 */
abstract class AbstractChatHistory implements ChatHistoryInterface
{
    /**
     * @var Message[]
     */
    protected array $history = [];

    /**
     * The conversation this history is bound to. Null until bound: histories
     * are constructible without their thread (the Agent binds the resolved
     * identity itself), or pre-bound via a constructor argument.
     */
    protected ?string $threadId = null;

    /**
     * Loading is deferred to first use, so a history can exist before its
     * thread is known. Flipped by ensureLoaded() exactly once.
     */
    protected bool $loaded = false;

    public function __construct(
        protected int $contextWindow = 50000,
        protected HistoryTrimmerInterface $trimmer = new HistoryTrimmer()
    ) {
    }

    /**
     * @throws ChatHistoryException when re-binding to a different thread.
     */
    public function setThreadId(string $threadId): void
    {
        if ($this->threadId !== null && $this->threadId !== $threadId) {
            throw new ChatHistoryException(
                "This chat history is bound to thread '{$this->threadId}' and cannot be re-pointed to '{$threadId}'."
            );
        }

        $this->threadId = $threadId;
    }

    public function getThreadId(): ?string
    {
        return $this->threadId;
    }

    /**
     * The bound thread, demanded: backends call this wherever storage is
     * touched, so using a thread-scoped history that was never bound fails
     * loudly instead of reading or writing a wrong conversation.
     *
     * @throws ChatHistoryException
     */
    protected function requireThreadId(): string
    {
        return $this->threadId ?? throw new ChatHistoryException(
            'This chat history is thread-scoped and no thread identity was given: '
            . 'pass threadId: to Agent::make(), or bind it via setThreadId().'
        );
    }

    /**
     * Load the thread on first access. Deferring the load out of the
     * constructor is what makes identity-free construction possible — the
     * Agent binds the thread before any message is read or written.
     */
    protected function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->loadThread();
    }

    /**
     * Backend hook: read the bound thread's messages into $this->history.
     * No-op by default (in-memory); durable backends override it with their
     * storage read, guarded by requireThreadId().
     */
    protected function loadThread(): void
    {
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

    /**
     * @throws ChatHistoryException
     */
    public function addMessage(Message $message): ChatHistoryInterface
    {
        $this->ensureLoaded();

        $this->history[] = $message;

        $this->trimHistory();

        $this->onNewMessage($message);

        $this->setMessages($this->history);

        return $this;
    }

    /**
     * @throws ChatHistoryException
     */
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
        $this->ensureLoaded();

        return $this->history;
    }

    /**
     * @throws ChatHistoryException
     */
    public function getLastMessage(): Message
    {
        $this->ensureLoaded();

        $message = end($this->history);

        if ($message === false) {
            throw new ChatHistoryException('No messages in the chat history. It may have been filled with too large single message.');
        }

        return $message;
    }

    public function flushAll(): ChatHistoryInterface
    {
        $this->ensureLoaded();

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
        $role = MessageRole::from($message['role']);
        $content = $this->deserializeContent($message['content'] ?? null);

        $item = match ($role) {
            MessageRole::ASSISTANT => new AssistantMessage($content),
            MessageRole::USER => new UserMessage($content),
            default => new Message($role, $content)
        };

        $this->deserializeMeta($message, $item);

        return $item;
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function deserializeToolCall(array $message): ToolCallMessage
    {
        $tools = array_map(function (array $tool): ToolCall {
            // Legacy histories may carry schema-side keys (e.g. 'parameters');
            // they are ignored — a call record needs no schema (ADR 0010).
            $call = new ToolCall(
                $tool['name'],
                $tool['callId'] ?? null,
                $tool['inputs'],
                $tool['description'] ?? null,
            );
            $call->setApprovalReason($tool['approvalReason'] ?? null);

            if (isset($tool['approval'])) {
                $call->setApprovalState(
                    ApprovalState::from($tool['approval']),
                    $tool['rejectReason'] ?? null
                );
            }

            return $call;
        }, $message['tools']);

        $item = new ToolCallMessage(tools: $tools);

        $this->deserializeMeta($message, $item);

        if ($content = $this->deserializeContent($message['content'] ?? null)) {
            $item->setContents($content);
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function deserializeToolCallResult(array $message): ToolResultMessage
    {
        $tools = array_map(function (array $tool): ToolCall {
            $call = new ToolCall(
                $tool['name'],
                $tool['callId'] ?? null,
                $tool['inputs'],
                $tool['description'] ?? null,
            );
            $call->setResult($this->deserializeToolResult($tool['result']))
                ->setApprovalReason($tool['approvalReason'] ?? null);

            if (isset($tool['approval'])) {
                $call->setApprovalState(
                    ApprovalState::from($tool['approval']),
                    $tool['rejectReason'] ?? null
                );
            }

            return $call;
        }, $message['tools']);

        return new ToolResultMessage($tools);
    }

    /**
     * A multimodal result is stored as an array of content blocks; an error
     * result wraps the blocks under an 'is_error' marker (legacy histories
     * never carry the marker and deserialize as non-error); a legacy (or
     * plain text) result is stored as a string.
     */
    protected function deserializeToolResult(mixed $result): string|ToolOutput
    {
        if (is_array($result)) {
            if (isset($result['is_error'])) {
                $blocks = $result['blocks'] ?? [];
                return new ToolOutput(
                    array_map(
                        $this->deserializeContentBlock(...),
                        isset($blocks[0]['type']) ? $blocks : []
                    ),
                    (bool) $result['is_error']
                );
            }

            return new ToolOutput(array_map(
                $this->deserializeContentBlock(...),
                isset($result[0]['type']) ? $result : []
            ));
        }

        return (string) $result;
    }

    /**
     * Deserialize content from the storage format to the ContentBlock array.
     *
     * Handles both legacy string format and the new content block array format.
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

        $item = match ($type) {
            ContentBlockType::TEXT => new TextContent(
                content: $block['content']
            ),
            ContentBlockType::REASONING => new ReasoningContent(
                content: $block['content'],
                id: $block['id'] ?? null
            ),
            ContentBlockType::SYSTEM => new SystemContent(
                content: $block['content']
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

        if (isset($block['meta'])) {
            $item->setMetadata($block['meta']);
        }

        return $item;
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
