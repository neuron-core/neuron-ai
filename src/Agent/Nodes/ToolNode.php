<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Agent\Tools\ToolRejectionHandler;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Tools\HasInterrupt;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\ToolsInterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Node;
use Throwable;

use function json_encode;

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
    public function __invoke(ToolCallEvent $event, AgentState $state): AIInferenceEvent|Generator
    {
        $resumeRequest = $this->consumeResumeRequest();

        // Skip chat history on resume to avoid duplicating the tool call message
        if (!$resumeRequest instanceof \NeuronAI\Workflow\Interrupt\InterruptRequest) {
            // Adding the tool call message to the chat history here allows the middleware to hook
            // the ToolNode before the tool call is added to the history.
            $this->addToChatHistory($state, $event->toolCallMessage);
        } else {
            // Inject resume request into tools before execution
            $this->injectResumeRequest($event->toolCallMessage->getTools(), $resumeRequest);
        }

        $toolCallResult = yield from $this->executeTools($event->toolCallMessage, $state);

        // Only carry the tool result message as the next turn in the conversation
        $event->inferenceEvent->setMessages($toolCallResult);

        // Go back to the AI provider
        return $event->inferenceEvent;
    }

    /**
     * Inject a resume request into tools that implement HasInterrupt.
     *
     * If the request is a ToolsInterruptRequest, each tool receives its
     * specific sub-request. Otherwise, all tools receive the same request.
     *
     * @param ToolInterface[] $tools
     */
    protected function injectResumeRequest(array $tools, InterruptRequest $resumeRequest): void
    {
        if ($resumeRequest instanceof ToolsInterruptRequest) {
            foreach ($tools as $tool) {
                if ($tool instanceof HasInterrupt) {
                    $tool->setInterruptRequest(null);
                    $tool->setResumeRequest($resumeRequest->getRequest($tool->getName()));
                }
            }
        } else {
            foreach ($tools as $tool) {
                if ($tool instanceof HasInterrupt) {
                    $tool->setInterruptRequest(null);
                    $tool->setResumeRequest($resumeRequest);
                }
            }
        }
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
            // Use custom run key if tool implements HasRunKey, otherwise use tool name
            $key = $tool instanceof HasRunKey ? $tool->getRunKey() : $tool->getName();

            $state->incrementToolRun($key);

            // Single tool max tries have the highest priority over the global max tries
            $runs = $tool->getMaxRuns() ?? $this->maxRuns;
            if ($state->getToolRuns($key) > $runs) {
                throw new ToolRunsExceededException("Tool {$tool->getName()} has been executed too many times - {$runs} - with arguments: ".json_encode($tool->getInputs()));
            }

            $tool->execute();

            // Check if the tool signaled an interrupt request
            if ($tool instanceof HasInterrupt && $tool->getInterruptRequest() instanceof \NeuronAI\Workflow\Interrupt\InterruptRequest) {
                $interrupt = $tool->getInterruptRequest();
                $toolsRequest = new ToolsInterruptRequest($interrupt->getMessage());
                $toolsRequest->addRequest($tool->getName(), $interrupt);
                throw new WorkflowInterrupt($toolsRequest, $this, $this->state, $this->event);
            }
        } catch (WorkflowInterrupt $interrupt) {
            throw $interrupt;
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

        if ($errorMessage !== null) {
            if ($tool instanceof Tool) {
                $tool->setResult($errorMessage);
            } else {
                // todo: Remove the else branch in v4
                $tool->setCallable(new ToolRejectionHandler($errorMessage));
                $tool->execute();
            }
        }
    }
}
