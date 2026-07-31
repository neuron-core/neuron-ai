<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Workflow\NodeInterface;

/**
 * A workflow node operating in the agent context.
 *
 * The chat history is a runtime service with its own storage — it is injected
 * into agent nodes as a constructor dependency and never travels through the
 * durable workflow state. Middleware targeting agent nodes access the history
 * through the node they wrap (never through their own constructor), so they can
 * only ever see the exact instance the node reads and writes.
 */
interface AgentNodeInterface extends NodeInterface
{
    public function getChatHistory(): ChatHistoryInterface;
}
