<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Tools;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use Throwable;

trait ExecuteToolsTrait
{
    protected int $maxRuns = 10;

    /** @var callable|null fn(Throwable $e, ToolInterface $tool): string */
    protected $errorHandler = null;

    protected function executeTools(ToolCallMessage $toolCallMessage, AgentState $state): Generator
    {
        foreach ($toolCallMessage->getTools() as $tool) {
            yield new ToolCallChunk($tool);
            $this->executeSingleTool($tool, $state);
            yield new ToolResultChunk($tool);
        }

        return new ToolResultMessage($toolCallMessage->getTools());
    }

    protected function executeSingleTool(ToolInterface $tool, AgentState $state): void
    {
        $this->onToolCalling($tool);

        try {
            $state->incrementToolRun($tool->getName());

            $runs = $tool->getMaxRuns() ?? $this->maxRuns;
            if ($state->getToolRuns($tool->getName()) > $runs) {
                throw new ToolRunsExceededException("Tool {$tool->getName()} has been executed too many times: {$runs}.");
            }

            $tool->execute();
        } catch (Throwable $e) {
            $this->handleToolError($e, $tool);
        } finally {
            $this->onToolCalled($tool);
        }
    }

    /**
     * Handle tool execution errors.
     * If an error handler is set, its return value becomes the tool result.
     * Returning null leaves the tool result unchanged. Otherwise, the exception is re-thrown.
     */
    protected function handleToolError(Throwable $e, ToolInterface $tool): void
    {
        if ($this->errorHandler === null) {
            throw $e;
        }

        $errorMessage = ($this->errorHandler)($e, $tool);

        if ($errorMessage !== null) {
            if ($tool instanceof Tool) {
                $tool->setResult($errorMessage);
            } else {
                $tool->setCallable(new ToolRejectionHandler($errorMessage));
                $tool->execute();
            }
        }
    }

    protected function onToolCalling(ToolInterface $tool): void
    {
    }

    protected function onToolCalled(ToolInterface $tool): void
    {
    }
}
