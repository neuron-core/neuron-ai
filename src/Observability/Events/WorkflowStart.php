<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Workflow\NodeInterface;

class WorkflowStart
{
    /**
     * @param NodeInterface[] $eventNodeMap
     */
    public function __construct(public array $eventNodeMap)
    {
    }
}
