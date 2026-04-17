<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class MergeWithContinuationNode extends Node
{
    public function __invoke(DocumentParallelEvent $event, WorkflowState $state): ContinuationEvent
    {
        $state->set('merge_results', $event->getAllResults());
        $state->set('merge_node_executed', true);
        return new ContinuationEvent('after-merge');
    }
}
