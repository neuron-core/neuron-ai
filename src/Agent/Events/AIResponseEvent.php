<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Events\Event;

/**
 * Event triggered when the AI provider returns a response.
 */
class AIResponseEvent implements Event
{
    public function __construct(
        public readonly Message $response,
    ) {
    }
}
