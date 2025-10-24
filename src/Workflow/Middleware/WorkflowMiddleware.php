<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Middleware;

use Generator;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

interface WorkflowMiddleware
{
    /**
     * Execute before the node runs.
     *
     * This method is called before the node's __invoke method executes.
     * Use this for validation, logging, state preparation, etc.
     *
     * @param NodeInterface $node The node about to execute
     * @param Event $event The event being processed
     * @param WorkflowState $state The current workflow state
     * @return void
     */
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void;

    /**
     * Execute after the node runs.
     *
     * This method is called after the node's __invoke method completes.
     * Use this for logging, caching, result transformation, etc.
     *
     * Note: For streaming nodes that return Generators, this is called after
     * the generator is fully consumed.
     */
    public function after(NodeInterface $node, Event $event, Event|Generator $result, WorkflowState $state): void;
}
