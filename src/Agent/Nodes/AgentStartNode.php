<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Workflow\Node;

/**
 * Initializes the state request from the segment's instructions and the start
 * payload, then routes to inference. RAG initializes the same request in
 * PreProcessNode before enriching it during retrieval.
 */
class AgentStartNode extends Node
{
    public function __invoke(AgentStartEvent $event, AgentState $state, AgentResources $resources): AIInferenceEvent
    {
        $state->resetToolRuns();

        // Clone so middleware can modify the run's working prompt
        // without leaking changes into the segment's instructions.
        $state->request = new InferenceRequest(
            instructions: clone $resources->instructions,
            messages: $event->messages,
            options: $event->options,
        );

        return AIInferenceEvent::fromRequest($state->request);
    }
}
