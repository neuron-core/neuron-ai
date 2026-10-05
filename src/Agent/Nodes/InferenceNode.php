<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Workflow\Node;

use function count;

/**
 * Base for nodes that perform AI provider inference (chat/stream transport
 * and structured output). Sharing a common ancestor lets middleware target
 * `InferenceNode::class` once and apply across every execution mode — the
 * event's exact class routes to one subclass per run, so attaching to a
 * single subclass would otherwise be dropped on the other route.
 */
abstract class InferenceNode extends Node implements AgentNodeInterface
{
    use ChatHistoryHelper;

    /**
     * The request's inbound messages are committed to the chat history only after
     * the provider call succeeds — a failed call must not persist a dangling
     * user message that breaks role alternation on the next attempt. Until that
     * write happens, the conversation sent to the provider is the stored
     * history plus the not-yet-committed inbound messages.
     *
     * @param Message[] $inbound
     * @return non-empty-list<Message>
     * @throws ChatHistoryException
     */
    protected function pendingConversation(ChatHistory $history, array $inbound): array
    {
        $messages = [...$history->getMessages(), ...$inbound];

        if ($messages === []) {
            throw new ChatHistoryException('Cannot run inference on an empty conversation.');
        }

        return $messages;
    }

    /**
     * The context of the turn travels with its question: the last user message
     * that is not a tool result. It goes after the question's own content, on a
     * copy, so every request of the turn carries it at the same place and the
     * stored message never does.
     *
     * @param non-empty-list<Message> $messages
     * @param array<int|string, ContentBlockInterface> $context
     * @return non-empty-list<Message>
     */
    protected function withContext(array $messages, array $context): array
    {
        if ($context === []) {
            return $messages;
        }

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if ($messages[$index] instanceof UserMessage && !$messages[$index] instanceof ToolResultMessage) {
                $messages[$index] = clone $messages[$index];

                foreach ($context as $block) {
                    $messages[$index]->addContent($block);
                }

                break;
            }
        }

        return $messages;
    }
}
