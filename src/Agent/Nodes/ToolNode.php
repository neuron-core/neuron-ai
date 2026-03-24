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
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Agent\Tools\ToolRejectionHandler;
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

    /**
     * @var callable|null fn(Throwable $e, ToolInterface $tool): string
     */
    protected $errorHandler;

    public function __construct(
        protected int $maxRuns = 10,
        ?callable $errorHandler = null
    ) {
        $this->errorHandler = $errorHandler;
    }

    /**
     * @throws ToolRunsExceededException
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
     * @throws ToolRunsExceededException
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
     * @throws ToolRunsExceededException If the tool exceeds its maximum retry attempts
     * @throws Throwable If the tool execution fails and no error handler is set
     */
    protected function executeSingleTool(ToolInterface $tool, AgentState $state): void
    {
        $this->emit('tool-calling', new ToolCalling($tool));

        try {
            $state->incrementToolRun($tool->getName());

            // Single tool max tries have the highest priority over the global max tries
            $runs = $tool->getMaxRuns() ?? $this->maxRuns;
            if ($state->getToolRuns($tool->getName()) > $runs) {
                throw new ToolRunsExceededException("Tool {$tool->getName()} has been executed too many times: {$runs}.");
            }

            $tool->execute();
        } catch (Throwable $e) {
            $this->handleError($e, $tool);
        } finally {
            $this->emit('tool-called', new ToolCalled($tool));
        }
    }

    /**
     * Handle tool execution errors.
     * If an error handler is set, the error message becomes the tool result.
     * Otherwise, the exception is re-thrown.
     *
     * @throws Throwable If no error handler is set
     */
    protected function handleError(Throwable $e, ToolInterface $tool): void
    {
        if ($this->errorHandler === null) {
            throw $e;
        }

        $errorMessage = ($this->errorHandler)($e, $tool);
        $tool->setCallable(new ToolRejectionHandler($errorMessage));
        $tool->execute();
    }
}
