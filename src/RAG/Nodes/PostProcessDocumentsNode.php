<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Observability\Events\PostProcessed;
use NeuronAI\Observability\Events\PostProcessing;
use NeuronAI\Observability\Observable;
use NeuronAI\RAG\Events\DocumentsProcessedEvent;
use NeuronAI\RAG\Events\DocumentsRetrievedEvent;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\Workflow\Node;

/**
 * Applies post-processors to retrieved documents.
 *
 * Post-processors can rerank, filter, or transform documents (e.g., relevance scoring, diversity filtering).
 */
class PostProcessDocumentsNode extends Node
{
    use Observable;

    /**
     * @param PostProcessorInterface[] $postProcessors
     */
    public function __construct(
        private readonly array $postProcessors
    ) {
    }

    /**
     * Apply post-processors sequentially to documents.
     */
    public function __invoke(DocumentsRetrievedEvent $event, AgentState $state): DocumentsProcessedEvent
    {
        $query = $event->query;
        $documents = $event->documents;

        foreach ($this->postProcessors as $processor) {
            $this->notify('rag-postprocessing', new PostProcessing($processor::class, $query, $documents));
            $documents = $processor->process($query, $documents);
            $this->notify('rag-postprocessed', new PostProcessed($processor::class, $query, $documents));
        }

        return new DocumentsProcessedEvent($query, $documents);
    }
}
