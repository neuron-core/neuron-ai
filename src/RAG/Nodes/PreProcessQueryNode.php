<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Observability\Events\PreProcessed;
use NeuronAI\Observability\Events\PreProcessing;
use NeuronAI\Observability\Observable;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;

/**
 * Applies preprocessors to the query before retrieval.
 *
 * Preprocessors can transform the query (e.g., query expansion, rewriting).
 */
class PreProcessQueryNode extends Node
{
    use Observable;

    /**
     * @param PreProcessorInterface[] $preProcessors
     */
    public function __construct(
        private readonly array $preProcessors
    ) {
    }

    /**
     * Apply preprocessors sequentially to the query.
     */
    public function __invoke(StartEvent $event, AgentState $state): AIInferenceEvent|QueryPreProcessedEvent
    {
        $query = $state->getChatHistory()->getLastMessage();

        foreach ($this->preProcessors as $processor) {
            $this->notify('rag-preprocessing', new PreProcessing($processor::class, $query));
            $query = $processor->process($query);
            $this->notify('rag-preprocessed', new PreProcessed($processor::class, $query));
        }

        return new QueryPreProcessedEvent($query);
    }
}
