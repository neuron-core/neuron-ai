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
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolInterface;
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
        foreach ($toolCallMessage->getTools() as $index => $tool) {
            yield new ToolCallChunk($tool);
            $this->executeSingleTool($tool, $state, $index);
            yield new ToolResultChunk($tool);
        }

        return new ToolResultMessage($toolCallMessage->getTools());
    }

    /**
     * Execute a single tool with proper error handling and retry logic.
     *
     * The tool execution is wrapped in a durable memo keyed by the tool call id
     * (or name + position when the provider supplies no call id). On replay — when
     * the node re-executes because its step crashed before completing — the recorded
     * result is restored onto the tool WITHOUT re-running it, so side-effecting tools
     * (emails, payments, ...) execute at most once.
     *
     * @throws ToolRunsExceededException If the tool exceeds its maximum retry attempts
     * @throws Throwable If the tool execution fails and no error handler is set
     */
    protected function executeSingleTool(ToolInterface $tool, AgentState $state, int $index): void
    {
        if ($tool->getApprovalState() === ApprovalState::Rejected) {
            // The rejection result was set by the ToolApproval middleware; the tool
            // must not run (ADR 0002/0003: a tool runs iff explicitly approved).
            $this->emit('tool-calling', new ToolCalling($tool));
            $this->emit('tool-called', new ToolCalled($tool));
            return;
        }

        $this->emit('tool-calling', new ToolCalling($tool));

        $memoKey = 'tool.' . ($tool->getCallId() ?? $tool->getName() . '.' . $index);

        try {
            $result = $this->memoize($memoKey, function () use ($tool, $state): string {
                $key = $tool->getRunKey();

                $state->incrementToolRun($key);

                // Single tool max tries have the highest priority over the global max tries
                $runs = $tool->getMaxRuns() ?? $this->maxRuns;
                if ($state->getToolRuns($key) > $runs) {
                    throw new ToolRunsExceededException("Tool {$tool->getName()} has been executed too many times - {$runs} - with arguments: ".json_encode($tool->getInputs()));
                }

                $tool->execute();
                return $tool->getResult();
            });

            // Restore the result onto the tool. On first execution this is a no-op-ish
            // re-set; on replay it is what makes execute() skippable.
            $tool->setResult($result);
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
            $tool->setResult($errorMessage);
        }
    }
}
