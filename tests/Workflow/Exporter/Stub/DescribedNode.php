<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Exporter\Stub;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Exporter\DescibeExporterTransitions;
use NeuronAI\Workflow\Exporter\ExporterTransition;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Declares a StopEvent return type but describes the given transitions, so
 * tests can tell described topology from inferred topology.
 */
class DescribedNode extends Node implements DescibeExporterTransitions
{
    public int $describeCalls = 0;

    /** @param list<ExporterTransition> $transitions */
    public function __construct(protected array $transitions)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        return new StopEvent();
    }

    public function describe(): array
    {
        $this->describeCalls++;

        return $this->transitions;
    }
}
