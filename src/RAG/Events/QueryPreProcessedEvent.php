<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Events;

use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Events\Event;

/**
 * Event emitted after query preprocessing.
 *
 * Triggers document retrieval from vector store.
 */
class QueryPreProcessedEvent implements Event
{
    /**
     * @param Message $query The (possibly transformed) query
     * @param AgentStartEvent $startEvent The run's start event, carried through the
     *        chain so the inference intent survives to InstructionsNode
     */
    public function __construct(
        public readonly Message $query,
        public readonly AgentStartEvent $startEvent
    ) {
    }
}
