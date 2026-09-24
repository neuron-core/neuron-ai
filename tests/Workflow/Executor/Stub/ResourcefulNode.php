<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;
use RuntimeException;

class ResourcefulNode extends Node
{
    public function __construct(protected bool $fail = false, protected bool $suspend = false)
    {
    }

    public function __invoke(SecondTextProcessEvent $event, WorkflowState $state, WorkflowResources $resources): StopEvent
    {
        if ($this->fail) {
            throw new RuntimeException('Probe failed.');
        }

        if ($this->suspend) {
            $this->awaitEvent($this->branchId ?? 'main');
        }

        return new StopEvent($resources->get('operation')() . ':' . $state->get('branch_value'));
    }
}
