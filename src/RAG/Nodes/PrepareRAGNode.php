<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\RAG\Events\QueryPreProcessEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Events\StartEvent;

/**
 * Gateway node that routes RAG workflow based on cache state.
 *
 * First execution: Initiates RAG pipeline with QueryPreProcessEvent
 * Tool loop (cached): Bypasses RAG pipeline, returns AIInferenceEvent directly
 */
class PrepareRAGNode extends Node
{
    /**
     * Route based on document retrieval cache.
     *
     * @return QueryPreProcessEvent|AIInferenceEvent
     */
    public function __invoke(StartEvent $event, AgentState $state): QueryPreProcessEvent|AIInferenceEvent
    {
        // Check if documents already retrieved (tool loop detection)
        if ($state->get('rag_documents_retrieved') === true) {
            // Cache hit: return AIInferenceEvent directly, bypassing RAG pipeline
            return new AIInferenceEvent(
                instructions: $state->get('rag_enriched_instructions'),
                tools: $state->get('rag_tools', [])
            );
        }

        // First run: initiate RAG pipeline
        $query = $state->getChatHistory()->getLastMessage();

        return new QueryPreProcessEvent($query);
    }
}
