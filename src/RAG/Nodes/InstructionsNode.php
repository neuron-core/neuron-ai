<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;
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
        private readonly SystemMessage $baseInstructions,
        private readonly array $tools
    ) {
    }

    /**
     * Inject documents into instructions.
     */
    public function __invoke(DocumentsProcessedEvent $event, AgentState $state): AIInferenceEvent
    {
        $instructions = new SystemMessage($this->baseInstructions->getContentBlocks());
        $instructions->addContent(new SystemContent($this->buildContextBlock($event->documents)));

        return new AIInferenceEvent(
            instructions: $instructions,
            tools: $this->tools
        );
    }

    private function buildContextBlock(array $documents): string
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
