<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;

class AgentEndNode extends Node
{
    public function __invoke(AgentOutputEvent $event, AgentState $state): StopEvent
    {
        return new StopEvent();
    }
}
