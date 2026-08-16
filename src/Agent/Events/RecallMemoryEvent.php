<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Routes a newly-created inference through the memory recall phase.
 */
class RecallMemoryEvent implements Event
{
    public function __construct(
        public AIInferenceEvent $inferenceEvent,
    ) {
    }
}
