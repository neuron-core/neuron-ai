<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Workflow\Node;

/**
 * The Agent's entry point: births the inference event by joining the agent
 * definition (instructions, tools) with the start event's run data (messages,
 * intent), then derives the routed event class. The start event stays pure
 * run data — definition capability enters the flow here, never at ignition.
 * RAG replaces this node with its retrieval chain ending in InstructionsNode,
 * which births the inference event the same way.
 */
class StartNode extends Node
{
    public function __construct(
        private readonly SystemMessage $instructions,
        private readonly array $tools,
    ) {
    }

    public function __invoke(AgentStartEvent $event, AgentState $state): AIInferenceEvent
    {
        // Clone so middleware can modify the event instructions
        // without leaking changes into the agent configuration.
        $inference = new AIInferenceEvent(clone $this->instructions, $this->tools);
        $inference->setMessages(...$event->getMessages());
        $inference->stream = $event->stream;
        $inference->outputClass = $event->outputClass;
        $inference->maxTries = $event->maxTries;

        return $inference->routed();
    }
}
