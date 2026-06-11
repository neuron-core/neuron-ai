<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\ContentHelper;
use NeuronAI\RAG\Events\DocumentsProcessedEvent;
use NeuronAI\Workflow\Node;

use function is_array;

/**
 * Enriches instructions with retrieved documents as context.
 *
 * Injects documents into instructions within <EXTRA-CONTEXT> tags.
 * Caches enriched instructions, tools, and documents in state for tool loop reuse.
 */
class InstructionsNode extends Node
{
    /**
     * @param string|ContentBlock[] $baseInstructions
     */
    public function __construct(
        private readonly string|array $baseInstructions,
        private readonly array $tools
    ) {
    }

    /**
     * Inject documents into instructions and cache for tool loop.
     */
    public function __invoke(DocumentsProcessedEvent $event, AgentState $state): AIInferenceEvent
    {
        $documents = $event->documents;
        $contextBlock = $this->buildContextBlock($documents);

        if (is_array($this->baseInstructions)) {
            return new AIInferenceEvent(
                instructions: $this->enrichArrayInstructions($contextBlock),
                tools: $this->tools
            );
        }

        return new AIInferenceEvent(
            instructions: $this->enrichStringInstructions($contextBlock),
            tools: $this->tools
        );
    }

    private function buildContextBlock(array $documents): string
    {
        $context = "\n\n<EXTRA-CONTEXT>";
        foreach ($documents as $document) {
            $context .= "Source Type: " . $document->getSourceType() . "\n" .
                "Source Name: " . $document->getSourceName() . "\n" .
                "Content: " . $document->getContent() . "\n\n";
        }
        return $context . "</EXTRA-CONTEXT>\n\n";
    }

    /**
     * @return SystemContent[]
     */
    private function enrichArrayInstructions(string $contextBlock): array
    {
        $enriched = [];
        foreach ($this->baseInstructions as $block) {
            $content = ContentHelper::removeDelimitedContent(
                $block->getContent(),
                "\n\n<EXTRA-CONTEXT>",
                "</EXTRA-CONTEXT>\n\n"
            );
            $enriched[] = new SystemContent($content . $contextBlock);
        }
        return $enriched;
    }

    private function enrichStringInstructions(string $contextBlock): string
    {
        $instructions = ContentHelper::removeDelimitedContent(
            $this->baseInstructions,
            "\n\n<EXTRA-CONTEXT>",
            "</EXTRA-CONTEXT>\n\n"
        );
        return $instructions . $contextBlock;
    }
}
