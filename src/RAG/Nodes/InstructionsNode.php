<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\HandleContent;
use NeuronAI\RAG\Events\DocumentsProcessedEvent;
use NeuronAI\Workflow\Node;

use const PHP_EOL;

/**
 * Enriches instructions with retrieved documents as context.
 *
 * Injects documents into instructions within <EXTRA-CONTEXT> tags.
 * Caches enriched instructions, tools, and documents in state for tool loop reuse.
 */
class InstructionsNode extends Node
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
            "\n\n<EXTRA-CONTEXT>",
            "</EXTRA-CONTEXT>\n\n"
        );

        // Add document context
        $instructions .= "\n\n<EXTRA-CONTEXT>";
        foreach ($documents as $document) {
            $instructions .= "Source Type: " . $document->getSourceType() . "\n" .
                "Source Name: " . $document->getSourceName() . "\n" .
                "Content: " . $document->getContent() . "\n\n";
        }
        $instructions .= "</EXTRA-CONTEXT>\n\n";

        return new AIInferenceEvent(
            instructions: $instructions,
            tools: $this->tools
        );
    }
}
