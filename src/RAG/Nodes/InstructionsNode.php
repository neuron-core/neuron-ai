<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\RAG\Events\DocumentsProcessedEvent;
use NeuronAI\Workflow\Node;

use function preg_replace;

/**
 * Gives the model the retrieved documents as context of the turn.
 *
 * The documents join the request's context within <EXTRA-CONTEXT> tags: they are
 * sent with the question, after its own content, and never stored. The instructions
 * stay the same from one turn to the next, so a provider can cache them and the
 * conversation.
 */
class InstructionsNode extends Node
{
    /**
     * Enrich the existing state request with documents, preserving changes
     * made earlier in the retrieval chain, then route to inference.
     */
    public function __invoke(DocumentsProcessedEvent $event, AgentState $state): AIInferenceEvent
    {
        if ($event->documents !== []) {
            $state->request->context[] = new TextContent($this->buildBlockContent($event->documents));
        }

        return AIInferenceEvent::fromRequest($state->request);
    }

    protected function buildBlockContent(array $documents): string
    {
        $context = "<EXTRA-CONTEXT>";
        foreach ($documents as $document) {
            $entry = "Source Type: " . $document->getSourceType() . "\n" .
                "Source Name: " . $document->getSourceName() . "\n" .
                "Content: " . $document->getContent() . "\n\n";
            // Retrieved text is untrusted: it must not close the block and pass for the developer's instructions
            $context .= preg_replace('~</(EXTRA-CONTEXT)~i', '<\\/$1', $entry);
        }
        return $context . "</EXTRA-CONTEXT>";
    }
}
