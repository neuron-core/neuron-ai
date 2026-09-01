<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\RecallMemoryEvent;
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
        private readonly array $tools,
        protected bool $memoryAvailable = false,
    ) {
    }

    /**
     * Inject documents into instructions. The emitted event is where RAG's
     * inference event is born, so the start event's inference intent is honored
     * here. When recall is requested and memory is available, it runs before
     * the routed inference class is derived.
     */
    public function __invoke(DocumentsProcessedEvent $event, AgentState $state): AIInferenceEvent|RecallMemoryEvent
    {
        $instructions = new SystemMessage($this->baseInstructions->getContentBlocks());
        $instructions->addContent(new SystemContent($this->buildBlockContent($event->documents)));

        $inference = new AIInferenceEvent(
            instructions: $instructions,
            tools: $this->tools
        );
        $inference->stream = $event->startEvent->stream;
        $inference->outputClass = $event->startEvent->outputClass;
        $inference->maxTries = $event->startEvent->maxTries;
        $inference->recallMemory = $event->startEvent->recallMemory;
        $inference->rememberMemory = $event->startEvent->rememberMemory;

        return $this->memoryAvailable && $event->startEvent->recallMemory
            ? new RecallMemoryEvent($inference)
            : $inference->routed();
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
