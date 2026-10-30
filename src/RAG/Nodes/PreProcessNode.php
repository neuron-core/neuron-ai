<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ClosesOpenTurn;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\Observability\PreProcessed;
use NeuronAI\RAG\Observability\PreProcessing;
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
    use ClosesOpenTurn;

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
            messages: $this->requestMessages($event, $resources),
            options: $event->options,
            context: $resources->context,
        );
        $messages = $event->messages;
        $query = $messages === [] ? $resources->history->getLastMessage() : end($messages);

        foreach ($this->preProcessors as $processor) {
            $this->emit(new PreProcessing($processor::class, $query));
            $query = $processor->process($query);
            $this->emit(new PreProcessed($processor::class, $query));
        }

        return new QueryPreProcessedEvent($query);
    }

    /**
     * The messages the turn's request starts with, as in AgentStartNode:
     * override it the same way.
     *
     * @return Message[]
     */
    protected function requestMessages(AgentStartEvent $event, AgentResources $resources): array
    {
        return $this->closeOpenTurn($resources->history, $event->messages);
    }
}
