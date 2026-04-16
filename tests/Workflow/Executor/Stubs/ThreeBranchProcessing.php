<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class ThreeBranchProcessing extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): ThreeBranchParallelEvent
    {
        return new ThreeBranchParallelEvent([
            'text' => new TextProcessEvent(),
            'image' => new ImageProcessEvent(),
            'summary' => new SummaryProcessEvent(),
        ]);
    }
}
