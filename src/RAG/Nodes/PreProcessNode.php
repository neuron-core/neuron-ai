<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Observability\Events\PreProcessed;
use NeuronAI\Observability\Events\PreProcessing;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use NeuronAI\Workflow\Node;

use function end;

/**
 * Applies preprocessors to the query before retrieval.
 *
 * Preprocessors can transform the query (e.g., query expansion, rewriting).
 */
class PreProcessNode extends Node implements AgentNodeInterface
{
    use ChatHistoryHelper;

    /**
     * @param PreProcessorInterface[] $preProcessors
     */
    public function __construct(
        ChatHistoryInterface $chatHistory,
        private readonly array $preProcessors
    ) {
        $this->chatHistory = $chatHistory;
    }

    /**
     * Apply preprocessors sequentially to the query.
     */
    public function __invoke(AgentStartEvent $event, AgentState $state): AIInferenceEvent|QueryPreProcessedEvent
    {
        // The inbound messages travel on the inference event and commit only
        // after the provider call succeeds (see InferenceNode), so a failed
        // turn never leaves a dangling user message that wedges the thread.
        $messages = $event->getMessages();
        $query = $messages === [] ? $this->chatHistory->getLastMessage() : end($messages);

        foreach ($this->preProcessors as $processor) {
            $this->emit(new PreProcessing($processor::class, $query));
            $query = $processor->process($query);
            $this->emit(new PreProcessed($processor::class, $query));
        }

        return new QueryPreProcessedEvent($query, $event);
    }
}
