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
 * Centralizes the runtime narrowing of the generic WorkflowMiddleware contract
 * (PHP contravariance forbids narrowing signatures in a subclass), giving
 * concrete middleware typed hooks. On a type mismatch onAgentContextMismatch()
 * fires instead — empty by default, override it to fail loudly when a silent
 * skip would be a safety hazard.
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

    protected function onAgentContextMismatch(NodeInterface $node, Event $event, WorkflowState $state): void
    {
    }
}
