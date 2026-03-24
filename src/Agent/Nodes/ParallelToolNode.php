<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Tools\ToolInterface;
use Spatie\Fork\Fork;
use Closure;
use Throwable;

use function array_map;
use function class_exists;
use function count;
use function extension_loaded;
use function is_array;
use function is_subclass_of;
use function serialize;
use function unserialize;

class ParallelToolNode extends ToolNode
{
    /**
     * @throws ToolException
     * @throws ToolRunsExceededException
     * @throws Throwable
     */
    protected function executeTools(ToolCallMessage $toolCallMessage, AgentState $state): Generator
    {
        // Fallback to sequential execution if pcntl is not available (e.g., Windows)
        if (!extension_loaded('pcntl')) {
            return parent::executeTools($toolCallMessage, $state);
        }

        // Fallback to sequential execution if spatie/fork is not installed
        if (!class_exists(Fork::class)) {
            return parent::executeTools($toolCallMessage, $state);
        }

        $tools = $toolCallMessage->getTools();

        // If there's only one tool, no need for concurrency
        if (count($tools) === 1) {
            return parent::executeTools($toolCallMessage, $state);
        }

        // Check max tries and notify before execution
        foreach ($tools as $tool) {
            $state->incrementToolRun($tool->getName());

            // Single tool max tries have the highest priority over the global max tries
            $maxTries = $tool->getMaxRuns() ?? $this->maxRuns;
            if ($state->getToolRuns($tool->getName()) > $maxTries) {
                throw new ToolRunsExceededException("Tool {$tool->getName()} has been attempted too many times: {$maxTries} attempts.");
            }

            $this->emit('tool-calling', new ToolCalling($tool));

            yield new ToolCallChunk($tool);
        }

        // Execute tools concurrently and collect serialized tool states
        $serializedTools = Fork::new()->run(
            ...array_map(
                fn (ToolInterface $tool): Closure => function () use ($tool): string {
                    try {
                        // Execute the tool - this mutates the tool's internal state
                        $tool->execute();

                        // Serialize the entire tool object with its new state
                        return serialize($tool);
                    } catch (Throwable $exception) {
                        // Wrap the exception info with the tool for proper error handling
                        return serialize([
                            'error' => true,
                            'exception_class' => $exception::class,
                            'exception_message' => $exception->getMessage(),
                            'exception_code' => $exception->getCode(),
                            'tool_name' => $tool->getName(),
                        ]);
                    }
                },
                $tools
            )
        );

        // Unserialize and replace tools with their executed state
        $executedTools = [];
        foreach ($serializedTools as $index => $serializedTool) {
            $data = unserialize($serializedTool);

            // Check if this is an error response
            if (is_array($data) && isset($data['error']) && $data['error'] === true) {
                $exceptionClass = $data['exception_class'];
                $exception = null;

                // Recreate the exception
                if (class_exists($exceptionClass) && is_subclass_of($exceptionClass, Throwable::class)) {
                    $exception = new $exceptionClass($data['exception_message'], (int) $data['exception_code']);
                } else {
                    $exception = new ToolException($data['exception_message'], (int) $data['exception_code']);
                }

                // Get the original tool to handle the error
                $originalTool = $tools[$index];
                $this->handleError($exception, $originalTool);
                $executedTools[$index] = $originalTool;
            } else {
                // Collect the executed tool with its new state
                $executedTools[$index] = $data;
            }

            yield new ToolResultChunk($executedTools[$index]);

            // Notify that the tool was called
            $this->emit('tool-called', new ToolCalled($executedTools[$index]));
        }

        // Return a new ToolCallResultMessage with the executed tools
        return new ToolResultMessage($executedTools);
    }
}
