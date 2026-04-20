<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Event emitted after context has been injected into both the system instructions
 * and (optionally) the message history.
 *
 * Consumed by InstructionsNode to assemble the AIInferenceEvent.
 */
class ContextInjectedEvent implements Event
{
    public function __construct(
        public readonly string $instructions,
    ) {
    }
}
