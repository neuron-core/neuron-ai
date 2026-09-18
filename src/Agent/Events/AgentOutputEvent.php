<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * The final response is available for output processing.
 * Output nodes may continue the workflow before returning StopEvent.
 */
class AgentOutputEvent implements Event
{
}
