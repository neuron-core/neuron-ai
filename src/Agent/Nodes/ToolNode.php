<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ToolMaxTriesException;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Node;
use Throwable;

/**
 * Node responsible for executing tool calls.
 */
class ToolNode extends Node
{
    use ChatHistoryHelper;

    public function __construct(
        protected int $maxTries = 10
    ) {
    }

    /**
     * @throws ToolMaxTriesException
     * @throws Throwable
     */
    public function __invoke(ToolCallEvent $event, AgentState $state): Generator
    {
        // Adding the tool call message to the chat history here allows the middleware to hook
        // the ToolNode before the tool call is added to the history.
        $this->addToChatHistory($state, $event->toolCallMessage);

        $toolCallResult = yield from $this->executeTools($event->toolCallMessage, $state);

        // Only carry the tool result message as the next turn in the conversation
        $event->inferenceEvent->setMessages($toolCallResult);

        // Go back to the AI provider
        return $event->inferenceEvent;
    }

    /**
     * @throws Throwable
     * @throws ToolMaxTriesException
     */
    protected function executeTools(ToolCallMessage $toolCallMessage, AgentState $state): Generator
    {
        foreach ($toolCallMessage->getTools() as $tool) {
            yield new ToolCallChunk($tool);
            $this->executeSingleTool($tool, $state);
            yield new ToolResultChunk($tool);
        }

        return new ToolResultMessage($toolCallMessage->getTools());
    }

    /**
     * Execute a single tool with proper error handling and retry logic.
     *
     * @throws ToolMaxTriesException If the tool exceeds its maximum retry attempts
     * @throws Throwable If the tool execution fails
     */
    protected function executeSingleTool(ToolInterface $tool, AgentState $state): void
    {
        $this->emit('tool-calling', new ToolCalling($tool));

        try {
            $state->incrementToolAttempt($tool->getName());

            // Single tool max tries have the highest priority over the global max tries
            $maxTries = $tool->getMaxTries() ?? $this->maxTries;
            if ($state->getToolAttempts($tool->getName()) > $maxTries) {
                throw new ToolMaxTriesException("Tool {$tool->getName()} has been attempted too many times: {$maxTries} attempts.");
            }

            $tool->execute();
        } finally {
            $this->emit('tool-called', new ToolCalled($tool));
        }
    }
}
