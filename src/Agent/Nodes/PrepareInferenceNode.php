<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StartEvent;

/**
 * Prepares inference configuration by emitting an AIInferenceEvent.
 *
 * This node creates an event containing instructions and tools that can be modified
 * by middleware before reaching the actual inference nodes (ChatNode, StreamingNode, or StructuredOutputNode).
 */
class PrepareInferenceNode extends Node
{
    public function __construct(
        private readonly string $instructions,
        private readonly array $tools,
    ) {
    }

    /**
     * Emit AIInferenceEvent with the configured instructions and tools.
     */
    public function __invoke(StartEvent $event, AgentState $state): AIInferenceEvent
    {
        return new AIInferenceEvent(
            instructions: $this->instructions,
            tools: $this->tools,
        );
    }
}
