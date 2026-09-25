<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Observability;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\NodeInterface;

use function array_map;

class WorkflowStart extends ObservabilityEvent
{
    /**
     * @param array<class-string<Event>, NodeInterface> $eventNodeMap
     */
    public function __construct(public array $eventNodeMap)
    {
    }

    public function toArray(): array
    {
        return array_map(static fn (NodeInterface $node): string => $node::class, $this->eventNodeMap);
    }
}
