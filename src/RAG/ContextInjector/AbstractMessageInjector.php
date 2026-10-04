<?php

declare(strict_types=1);

namespace NeuronAI\RAG\ContextInjector;

use NeuronAI\RAG\Document;

use const PHP_EOL;

/**
 * Base class for message-history-based context injectors.
 *
 * Provides shared document formatting logic (per-document template and separator)
 * mirroring the approach used in {@see SystemPromptInjector}.
 *
 * Extend this class and implement {@see inject()} to define where in the last
 * user message the context block should be inserted (before or after existing content).
 * The $instructions string is returned unchanged — these injectors operate on
 * the chat history stored in $state.
 */
abstract class AbstractMessageInjector implements ContextInjectorInterface
{
    public function __construct(
        protected string $documentSeparator = PHP_EOL . PHP_EOL
    ) {
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

    /**
     * Format all documents into a single context string.
     *
     * @param Document[] $documents
     */
    protected function formatContext(array $documents): string
    {
        return implode(
            $this->documentSeparator,
            array_map($this->formatDocument(...), $documents)
        );
    }
}
