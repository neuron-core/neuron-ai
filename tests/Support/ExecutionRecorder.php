<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

/** Records emitted execution facts for assertions on eager and structured terminals. */
final class ExecutionRecorder
{
    public ?WorkflowState $state = null;
    public ?ExecutionContext $context = null;
    public array $nodes = [];

    public function __construct(Workflow $definition)
    {
        $definition->subscribe(WorkflowStart::class, function (WorkflowStart $event): void {
            $this->context = $event->execution;
            $this->nodes = $event->eventNodeMap;
        });
        $definition->subscribe(WorkflowEnd::class, function (WorkflowEnd $event): void {
            $this->state = $event->state;
        });
    }
}
