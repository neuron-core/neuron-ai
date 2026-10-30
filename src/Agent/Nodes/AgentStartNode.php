<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ClosesOpenTurn;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Node;

/**
 * Initializes the state request from the segment's instructions and the start
 * payload, closing a turn an earlier run left open, then routes to inference.
 * RAG initializes the same request in PreProcessNode before enriching it during
 * retrieval.
 */
class AgentStartNode extends Node
{
    use ClosesOpenTurn;

    public function __invoke(AgentStartEvent $event, AgentState $state, AgentResources $resources): AIInferenceEvent
    {
        $state->resetToolRuns();

        // Clone so middleware can modify the run's working prompt
        // without leaking changes into the segment's instructions.
        $state->request = new InferenceRequest(
            instructions: clone $resources->instructions,
            messages: $this->requestMessages($event, $resources),
            options: $event->options,
            context: $resources->context,
        );

        return AIInferenceEvent::fromRequest($state->request);
    }

    /**
     * The messages the turn's request starts with: the start event's messages,
     * after the ones that close a turn an earlier run left open. Override it to
     * start a turn another way. Its result is saved with this step: a run
     * recovered with run() reuses it rather than calling it again.
     *
     * @return Message[]
     */
    protected function requestMessages(AgentStartEvent $event, AgentResources $resources): array
    {
        return $this->closeOpenTurn($resources->history, $event->messages);
    }
}
