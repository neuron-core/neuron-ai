<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

class MiddlewareRecord
{
    /**
     * @param string $method The method called: 'before' or 'after'
     * @param NodeInterface $node The node being executed
     * @param Event $event The event passed to the middleware
     * @param WorkflowState $state The workflow state at call time
     */
    public function __construct(
        public readonly string $method,
        public readonly NodeInterface $node,
        public readonly Event $event,
        public readonly WorkflowState $state,
    ) {
    }
}
