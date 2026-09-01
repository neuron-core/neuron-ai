<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Exporter\DescibeExporterTransitions;
use NeuronAI\Workflow\Exporter\ParallelTransition;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class DocumentParallelProcessing extends Node implements DescibeExporterTransitions
{
    public function __invoke(StartEvent $event, WorkflowState $state): DocumentParallelEvent
    {
        return new DocumentParallelEvent([
            'text' => new TextProcessEvent(),
            'image' => new ImageProcessEvent(),
        ]);
    }

    public function describe(): array
    {
        return [
            new ParallelTransition(DocumentParallelEvent::class, [
                'text' => TextProcessEvent::class,
                'image' => ImageProcessEvent::class,
            ]),
        ];
    }
}
