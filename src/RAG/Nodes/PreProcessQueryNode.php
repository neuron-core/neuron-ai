<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\PreProcessed;
use NeuronAI\Observability\Events\PreProcessing;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use NeuronAI\Workflow\Node;

/**
 * Applies preprocessors to the query before retrieval.
 *
 * Preprocessors can transform the query (e.g., query expansion, rewriting).
 */
class PreProcessQueryNode extends Node
{
    use ChatHistoryHelper;

    /**
     * @param PreProcessorInterface[] $preProcessors
     */
    public function __construct(
        private readonly array $preProcessors
    ) {
    }

    /**
     * Apply preprocessors sequentially to the query.
     *
     * @throws InspectorException
     */
    public function __invoke(AgentStartEvent $event, AgentState $state): AIInferenceEvent|QueryPreProcessedEvent
    {
        $this->addToChatHistory($state, $event->getMessages());

        $query = $state->getChatHistory()->getLastMessage();

        foreach ($this->preProcessors as $processor) {
            EventBus::emit('rag-preprocessing', $this, new PreProcessing($processor::class, $query));
            $query = $processor->process($query);
            EventBus::emit('rag-preprocessed', $this, new PreProcessed($processor::class, $query));
        }

        return new QueryPreProcessedEvent($query);
    }
}
