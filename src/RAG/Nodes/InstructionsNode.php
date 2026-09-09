<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\RecallMemoryEvent;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\RAG\Events\DocumentsProcessedEvent;
use NeuronAI\Workflow\Node;

/**
 * Enriches instructions with retrieved documents as context.
 *
 * The base instruction blocks are left untouched (preserving prompt cache flags),
 * and documents are appended as a trailing block within <EXTRA-CONTEXT> tags.
 */
class InstructionsNode extends Node
{
    public function __construct(
        protected bool $memoryAvailable = false,
    ) {
    }

    /**
     * Enrich the existing state request with documents, preserving changes
     * made earlier in the retrieval chain, then route to recall or inference.
     */
    public function __invoke(DocumentsProcessedEvent $event, AgentState $state): AIInferenceEvent|RecallMemoryEvent
    {
        $state->request->instructions->addContent(new SystemContent($this->buildBlockContent($event->documents)));

        return $this->memoryAvailable && $state->request->options->recallMemory
            ? new RecallMemoryEvent()
            : AIInferenceEvent::fromRequest($state->request);
    }

    private function buildBlockContent(array $documents): string
    {
        $context = "<EXTRA-CONTEXT>";
        foreach ($documents as $document) {
            $context .= "Source Type: " . $document->getSourceType() . "\n" .
                "Source Name: " . $document->getSourceName() . "\n" .
                "Content: " . $document->getContent() . "\n\n";
        }
        return $context . "</EXTRA-CONTEXT>";
    }
}
