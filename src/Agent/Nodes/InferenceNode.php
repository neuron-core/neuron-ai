<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Workflow\Node;

/**
 * Base class for the provider-inference nodes an Agent composes per execution mode
 * (ChatNode, StreamingNode, StructuredOutputNode).
 *
 * The three modes are siblings, not subclasses of one another. Without a shared
 * base, node-specific middleware attached to one concrete class (e.g. ChatNode)
 * silently does nothing in the other modes. Because middleware matching is
 * subclass-aware (instanceof), attaching middleware to InferenceNode::class
 * targets every inference mode at once.
 */
abstract class InferenceNode extends Node
{
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
    protected function pendingConversation(AgentState $state, array $inbound): array
    {
        $messages = [...$state->getChatHistory()->getMessages(), ...$inbound];

        if ($messages === []) {
            throw new ChatHistoryException('Cannot run inference on an empty conversation.');
        }

        return $messages;
    }
}
