<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class DocumentParallelProcessing extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): DocumentParallelEvent
    {
        return new DocumentParallelEvent([
            'text' => new TextProcessEvent(),
            'image' => new ImageProcessEvent(),
        ]);
    }
}
