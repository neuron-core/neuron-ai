<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Nodes\AgentStartNode;

/**
 * Counts how often a run asks for the messages its turn starts with.
 */
class CountingStartNode extends AgentStartNode
{
    public static int $calls = 0;

    protected function requestMessages(AgentStartEvent $event, AgentResources $resources): array
    {
        self::$calls++;

        return parent::requestMessages($event, $resources);
    }
}
