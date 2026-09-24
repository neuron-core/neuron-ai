<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Workflow\NodeInterface;

/**
 * A workflow node operating in the agent context: it runs with AgentState and
 * reads the provider, chat history, instructions and tools from AgentResources.
 * AgentMiddleware targets these nodes.
 */
interface AgentNodeInterface extends NodeInterface
{
}
