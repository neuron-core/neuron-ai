<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class ThreeBranchMergeNode extends Node
{
    public function __invoke(ThreeBranchParallelEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('merge_results', $event->branchResults);
        $state->set('merge_node_executed', true);
        return new StopEvent();
    }
}
