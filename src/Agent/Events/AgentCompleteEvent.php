<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Event;

/**
 * Event triggered when the agent completes its execution without tool calls.
 */
class AgentCompleteEvent implements Event
{
    public function __construct(
        public readonly Message $finalResponse,
    ) {
    }
}
