<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Events\Event;

/**
 * Carries the final inference messages to the memory storage phase.
 */
class StoreMemoryEvent implements Event
{
    /**
     * @param Message[] $messages
     */
    public function __construct(
        public array $messages,
    ) {
    }
}
