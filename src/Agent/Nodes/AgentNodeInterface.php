<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Workflow\NodeInterface;

/**
 * A workflow node operating in the agent context. The chat history is injected
 * as a constructor dependency; middleware access it through the node they wrap
 * so they always see the instance the node reads and writes.
 */
interface AgentNodeInterface extends NodeInterface
{
    public function getChatHistory(): ChatHistoryInterface;
}
