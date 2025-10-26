<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\HandleContent;
use NeuronAI\RAG\Events\DocumentsProcessedEvent;
use NeuronAI\Workflow\Node;

/**
 * Enriches instructions with retrieved documents as context.
 *
 * Injects documents into instructions within <EXTRA-CONTEXT> tags.
 * Caches enriched instructions, tools, and documents in state for tool loop reuse.
 */
class EnrichInstructionsNode extends Node
{
    use HandleContent;

    public function __construct(
        private readonly string $baseInstructions,
        private readonly array $tools
    ) {
    }

    /**
     * Inject documents into instructions and cache for tool loop.
     */
    public function __invoke(DocumentsProcessedEvent $event, AgentState $state): AIInferenceEvent
    {
        $documents = $event->documents;

        // Remove old context to avoid infinite growth
        $instructions = $this->removeDelimitedContent(
            $this->baseInstructions,
            '<EXTRA-CONTEXT>',
            '</EXTRA-CONTEXT>'
        );

        // Add document context
        $instructions .= '<EXTRA-CONTEXT>';
        foreach ($documents as $document) {
            $instructions .= "Source Type: " . $document->getSourceType() . \PHP_EOL .
                "Source Name: " . $document->getSourceName() . \PHP_EOL .
                "Content: " . $document->getContent() . \PHP_EOL . \PHP_EOL;
        }
        $instructions .= '</EXTRA-CONTEXT>';

        return new AIInferenceEvent(
            instructions: $instructions,
            tools: $this->tools
        );
    }
}
