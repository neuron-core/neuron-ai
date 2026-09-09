<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\RecallMemoryEvent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Workflow\Node;

/**
 * Initializes the state request from the agent definition and start payload,
 * then routes through recall when requested and available. RAG initializes
 * the same request in PreProcessNode before enriching it during retrieval.
 */
class StartNode extends Node
{
    public function __construct(
        protected SystemMessage $instructions,
        protected array $tools,
        protected bool $memoryAvailable = false,
    ) {
    }

    public function __invoke(AgentStartEvent $event, AgentState $state): AIInferenceEvent|RecallMemoryEvent
    {
        // Clone so middleware can modify the event instructions
        // without leaking changes into the agent configuration.
        $state->request = new InferenceRequest(
            instructions: clone $this->instructions,
            tools: $this->tools,
            messages: $event->messages,
            options: $event->options,
        );

        return $this->memoryAvailable && $state->request->options->recallMemory
            ? new RecallMemoryEvent()
            : AIInferenceEvent::fromRequest($state->request);
    }
}
