<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\Message;
use JsonSerializable;

interface ChatHistoryInterface extends JsonSerializable
{
    /**
     * Bind this history to its conversation. A history is thread-scoped by
     * nature, but constructible without its thread: the Agent binds the
     * resolved thread identity itself before first use — developers rarely
     * call this by hand. Assign-once in effect: re-setting the same id is a
     * no-op; a different id throws (re-pointing a conversation at another
     * thread is never legitimate).
     */
    public function setThreadId(string $threadId): void;

    /**
     * The storage key of the conversation this history reads and writes, or
     * null while unbound (constructed without a thread, not yet bound by the
     * Agent). The framework's thread identity lives on the Agent; this is
     * the history's own key, validated against it.
     */
    public function getThreadId(): ?string;

    public function addMessage(Message $message): ChatHistoryInterface;

    /**
     * @return Message[]
     */
    public function getMessages(): array;

    public function getLastMessage(): Message|false;

    public function flushAll(): ChatHistoryInterface;

    public function calculateTotalUsage(): int;
}
