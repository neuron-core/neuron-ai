<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\RAG\Events\ContextInjectedEvent;
use NeuronAI\Workflow\Node;

/**
 * Assembles the AIInferenceEvent from the enriched instructions produced by
 * ContextInjectionNode.
 *
 * This node intentionally has no injection logic — all context formatting and
 * injection is handled upstream by {@see ContextInjectionNode}.
 */
class InstructionsNode extends Node
{
    /**
     * @param array $tools Resolved tool list.
     */
    public function __construct(
        private readonly array $tools,
    ) {
    }

    public function __invoke(ContextInjectedEvent $event, AgentState $state): AIInferenceEvent
    {
        return new AIInferenceEvent(
            instructions: $event->instructions,
            tools: $this->tools,
        );
    }
}
