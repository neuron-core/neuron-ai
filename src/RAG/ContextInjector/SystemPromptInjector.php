<?php

declare(strict_types=1);

namespace NeuronAI\RAG\ContextInjector;

use NeuronAI\Agent\AgentState;
use NeuronAI\HandleContent;
use NeuronAI\RAG\Document;

use const PHP_EOL;

/**
 * Default injector: formats documents and appends them as a context block to the
 * system instructions.
 *
 * Each document is formatted via {@see formatDocument()} and joined with
 * {@see $documentSeparator}. The resulting block is wrapped in <EXTRA-CONTEXT>
 * tags. Any previously injected block is stripped first to prevent unbounded
 * growth across tool-call loops.
 *
 * Extend this class to customise per-document formatting or the separator.
 */
class SystemPromptInjector implements ContextInjectorInterface
{
    use HandleContent;

    public function __construct(
        protected string $documentSeparator = PHP_EOL . PHP_EOL
    ) {
    }

    /**
     * @param Document[] $documents
     */
    public function inject(array $documents, string $instructions, AgentState $state): string
    {
        $formatted = array_map($this->formatDocument(...), $documents);
        $context = implode($this->documentSeparator, $formatted);

        // Strip any prior context block to prevent infinite growth
        $instructions = $this->removeDelimitedContent(
            $instructions,
            '<EXTRA-CONTEXT>',
            '</EXTRA-CONTEXT>'
        );

        return $instructions . PHP_EOL . '<EXTRA-CONTEXT>' . PHP_EOL . $context . PHP_EOL . '</EXTRA-CONTEXT>';
    }

    /**
     * Format a single document for inclusion in the context block.
     *
     * Override in a subclass to customise the per-document template.
     */
    protected function formatDocument(Document $document): string
    {
        return 'Source Type: ' . $document->getSourceType() . PHP_EOL
            . 'Source Name: ' . $document->getSourceName() . PHP_EOL
            . 'Content: ' . $document->getContent();
    }
}
