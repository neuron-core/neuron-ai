<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Exporter\Stub;

use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class NullableReturnNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): ?FirstEvent
    {
        return new FirstEvent();
    }
}
