<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\RAG\ContextInjector\ContextInjectorInterface;
use NeuronAI\RAG\Events\ContextInjectedEvent;
use NeuronAI\RAG\Events\DocumentsProcessedEvent;
use NeuronAI\Workflow\Node;

/**
 * Handles all context injection for RAG inference.
 *
 * Delegates to {@see ContextInjectorInterface}, which may modify the instructions
 * string (system-prompt injection) or the chat history in $state (message injection),
 * or both.
 *
 * Emits {@see ContextInjectedEvent} carrying the enriched instructions for
 * consumption by InstructionsNode.
 */
class ContextInjectionNode extends Node
{
    public function __construct(
        private readonly string $baseInstructions,
        private readonly ContextInjectorInterface $contextInjector,
    ) {
    }

    public function __invoke(DocumentsProcessedEvent $event, AgentState $state): ContextInjectedEvent
    {
        $instructions = $this->contextInjector->inject($event->documents, $this->baseInstructions, $state);

        return new ContextInjectedEvent($instructions);
    }
}
