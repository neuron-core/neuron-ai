<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Tools\ExecuteToolsTrait;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Node;

/**
 * Node responsible for executing tool calls.
 */
class ToolNode extends Node
{
    use ChatHistoryHelper;
    use ExecuteToolsTrait;

    public function __construct(
        int $maxRuns = 10,
        ?callable $errorHandler = null
    ) {
        $this->maxRuns = $maxRuns;
        $this->errorHandler = $errorHandler;
    }

    /**
     * @throws ToolRunsExceededException
     * @throws \Throwable
     */
    public function __invoke(ToolCallEvent $event, AgentState $state): Generator
    {
        // Store the tool call in chat history before execution so middleware
        // can intercept the ToolNode before the call is recorded.
        // Clone tools to prevent execution mutations (setResult) from leaking
        // into the historical tool_call message.
        $clonedTools = array_map(fn (ToolInterface $t) => clone $t, $event->toolCallMessage->getTools());
        $this->addToChatHistory($state, new ToolCallMessage(
            $event->toolCallMessage->getContent(),
            $clonedTools,
        ));

        $toolCallResult = yield from $this->executeTools($event->toolCallMessage, $state);

        // Only carry the tool result message as the next turn in the conversation
        $event->inferenceEvent->setMessages($toolCallResult);

        // Go back to the AI provider
        return $event->inferenceEvent;
    }

    protected function onToolCalling(ToolInterface $tool): void
    {
        $this->emit('tool-calling', new ToolCalling($tool));
    }

    protected function onToolCalled(ToolInterface $tool): void
    {
        $this->emit('tool-called', new ToolCalled($tool));
    }
}
