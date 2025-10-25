<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\Events\QueryPreProcessEvent;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use NeuronAI\Workflow\Node;

/**
 * Applies preprocessors to the query before retrieval.
 *
 * Preprocessors can transform the query (e.g., query expansion, rewriting).
 */
class PreProcessQueryNode extends Node
{
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
    public function __invoke(QueryPreProcessEvent $event, AgentState $state): QueryPreProcessedEvent
    {
        $query = $event->query;

        foreach ($this->preProcessors as $processor) {
            $query = $processor->process($query);
        }

        return new QueryPreProcessedEvent($query);
    }
}
