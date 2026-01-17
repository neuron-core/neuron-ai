<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Middleware;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

interface WorkflowMiddleware
{
    /**
     * Execute before the node runs.
     * This method is called before the node's __invoke method executes.
     */
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void;

    /**
     * Execute after the node runs.
     *
     * This method is called after the node's __invoke method completes.
     * For streaming nodes that return Generators, this is called after
     * the generator is fully consumed and the final Event is available.
     *
     * @param NodeInterface $node The node that executed
     * @param Event $result The final result event returned by the node
     * @param WorkflowState $state The current workflow state
     */
    public function after(NodeInterface $node, Event $result, WorkflowState $state): void;
}
