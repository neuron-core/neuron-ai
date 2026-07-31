<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

/**
 * Base for middleware that operate in the agent context.
 *
 * The generic WorkflowMiddleware contract receives NodeInterface/WorkflowState —
 * the Workflow layer cannot know agent types, and PHP parameter contravariance
 * forbids narrowing the signature in a subclass. This base centralizes the
 * runtime narrowing once (template method) so concrete agent middleware get
 * fully-typed hooks, including the node's chat history via AgentNodeInterface.
 *
 * When the types don't match — the middleware was attached outside the agent
 * context — onAgentContextMismatch() fires. It is empty by default; middleware
 * whose silent skip would be a safety hazard (e.g. an approval gate) override
 * it to fail loudly.
 */
abstract class AgentMiddleware implements WorkflowMiddleware
{
    final public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if ($node instanceof AgentNodeInterface && $state instanceof AgentState) {
            $this->beforeAgentNode($node, $event, $state);
            return;
        }

        $this->onAgentContextMismatch($node, $event, $state);
    }

    final public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        if ($node instanceof AgentNodeInterface && $state instanceof AgentState) {
            $this->afterAgentNode($node, $result, $state);
        }
    }

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state): void
    {
    }

    protected function afterAgentNode(AgentNodeInterface $node, Event $result, AgentState $state): void
    {
    }

    /**
     * Called when this middleware is attached to a node outside the agent
     * context. Receives the raw types so an override can report which half of
     * the contract broke. Empty by default — a mismatch reaction is a
     * before()-only concern, so it never fires twice per node execution.
     */
    protected function onAgentContextMismatch(NodeInterface $node, Event $event, WorkflowState $state): void
    {
    }
}
