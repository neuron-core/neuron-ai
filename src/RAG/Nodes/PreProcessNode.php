<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Tools\ToolInterface;
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
     * @param ToolInterface[] $tools
     */
    public function __construct(
        ChatHistoryInterface $chatHistory,
        protected array $preProcessors,
        protected SystemMessage $instructions,
        protected array $tools,
    ) {
        $this->chatHistory = $chatHistory;
    }

    /**
     * Apply preprocessors sequentially to the query.
     */
    public function __invoke(AgentStartEvent $event, AgentState $state): QueryPreProcessedEvent
    {
        $state->request = new InferenceRequest(
            instructions: clone $this->instructions,
            tools: $this->tools,
            messages: $event->messages,
            options: $event->options,
        );
        $messages = $state->request->messages;
        $query = $messages === [] ? $this->chatHistory->getLastMessage() : end($messages);

        foreach ($this->preProcessors as $processor) {
            $this->emit(new PreProcessing($processor::class, $query));
            $query = $processor->process($query);
            $this->emit(new PreProcessed($processor::class, $query));
        }

        return new QueryPreProcessedEvent($query);
    }
}
