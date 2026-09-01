<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use DateTimeImmutable;

class SleepUntilNode extends Node
{
    public function __construct(private readonly ?DateTimeImmutable $wakeAt = null)
    {
    }

    public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent
    {
        $state->set('sleep_until_node_executed', true);

        // The engine does not enforce timeliness — any wakeAt suspends here.
        $this->sleepUntil($this->wakeAt ?? new DateTimeImmutable('+1 hour'));

        // Reached only on resume.
        $state->set('sleep_resumed', true);

        return new SecondEvent('Continued after sleep');
    }
}
