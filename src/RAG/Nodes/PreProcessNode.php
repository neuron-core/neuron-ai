<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
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
    /**
     * @param PreProcessorInterface[] $preProcessors
     */
    public function __construct(
        protected array $preProcessors,
    ) {
    }

    /**
     * Apply preprocessors sequentially to the query.
     */
    public function __invoke(AgentStartEvent $event, AgentState $state, AgentResources $resources): QueryPreProcessedEvent
    {
        $state->resetToolRuns();
        $state->request = new InferenceRequest(
            instructions: clone $resources->instructions,
            messages: $event->messages,
            options: $event->options,
        );
        $messages = $state->request->messages;
        $query = $messages === [] ? $resources->history->getLastMessage() : end($messages);

        foreach ($this->preProcessors as $processor) {
            $this->emit(new PreProcessing($processor::class, $query));
            $query = $processor->process($query);
            $this->emit(new PreProcessed($processor::class, $query));
        }

        return new QueryPreProcessedEvent($query);
    }
}
