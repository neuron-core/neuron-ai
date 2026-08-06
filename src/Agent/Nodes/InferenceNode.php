<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Node;

/**
 * Base for nodes that perform AI provider inference (chat, streaming,
 * structured output). Sharing a common ancestor lets middleware target
 * `InferenceNode::class` once and apply across every execution mode — the
 * Agent composes exactly one of these per mode, so attaching to any single
 * subclass would otherwise be dropped in the other two modes.
 */
abstract class InferenceNode extends Node implements AgentNodeInterface
{
    use ChatHistoryHelper;

    public function __construct(
        protected AIProviderInterface $provider,
        ChatHistoryInterface $chatHistory,
    ) {
        $this->chatHistory = $chatHistory;
    }

    /**
     * The event's inbound messages are committed to the chat history only after
     * the provider call succeeds — a failed call must not persist a dangling
     * user message that breaks role alternation on the next attempt. Until that
     * write happens, the conversation sent to the provider is the stored
     * history plus the not-yet-committed inbound messages.
     *
     * @param Message[] $inbound
     * @return non-empty-list<Message>
     * @throws ChatHistoryException
     */
    protected function pendingConversation(array $inbound): array
    {
        $messages = [...$this->chatHistory->getMessages(), ...$inbound];

        if ($messages === []) {
            throw new ChatHistoryException('Cannot run inference on an empty conversation.');
        }

        return $messages;
    }
}
