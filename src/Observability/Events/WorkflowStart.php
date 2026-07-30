<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\NodeInterface;

class WorkflowStart extends ObservabilityEvent
{
    /**
     * @param NodeInterface[] $eventNodeMap
     */
    public function __construct(public array $eventNodeMap)
    {
    }
}
