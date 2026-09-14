<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;

class OutputNode extends Node
{
    public function __invoke(AgentOutputEvent $event, AgentState $state): StopEvent
    {
        $state->set('output', $state->getMessage()?->getContent());
        $state->set('output_runs', $state->get('output_runs', 0) + 1);
        return new StopEvent();
    }
}
