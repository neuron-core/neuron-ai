<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Execution\Stubs;

use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node\ParallelNode;
use NeuronAI\Workflow\WorkflowState;

class DocumentProcessingNode extends ParallelNode
{
    public function __invoke(StartEvent $event, WorkflowState $state): ParallelEvent
    {
        return new ParallelEvent([
            'text' => new TextProcessEvent(),
            'image' => new ImageProcessEvent(),
        ]);
    }

    public function merge(array $results, WorkflowState $state): MergeEvent
    {
        $analysis = [
            'text' => $state->get('branches.text.processedText'),
            'image' => $state->get('branches.image.processedImage'),
        ];
        $state->set('analysis', $analysis);
        return new MergeEvent();
    }
}
