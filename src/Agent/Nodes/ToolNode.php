<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ToolMaxTriesException;
use NeuronAI\Observability\Events\AgentError;
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
    public function __construct(
        protected int $maxTries = 10
    ) {
    }

    /**
     * @throws ToolMaxTriesException
     * @throws Throwable
     */
    public function __invoke(ToolCallEvent $event, AgentState $state): AIInferenceEvent
    {
        $toolCallResult = $this->executeTools($event->toolCallMessage, $state);

        // Note: ToolCallMessage is already in chat history from ChatNode
        // Only add the tool result message
        $state->getChatHistory()->addMessage($toolCallResult);

        // Go back to the AI provider
        return $event->inferenceEvent;
    }

    /**
     * @throws Throwable
     * @throws ToolMaxTriesException
     */
    protected function executeTools(ToolCallMessage $toolCallMessage, AgentState $state): ToolResultMessage
    {
        foreach ($toolCallMessage->getTools() as $tool) {
            $this->executeSingleTool($tool, $state);
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
        } catch (Throwable $exception) {
            $this->emit('error', new AgentError($exception));
            throw $exception;
        }

        $this->emit('tool-called', new ToolCalled($tool));
    }
}
