<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Exceptions\ToolMaxTriesException;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Tools\ToolInterface;
use Spatie\Fork\Fork;

/**
 * Node responsible for executing tool calls in parallel using spatie/fork.
 *
 * This implementation requires:
 * - The pcntl PHP extension (available on Unix/Mac systems)
 * - The spatie/fork package
 *
 * Note: pcntl only works in CLI processes, not in a web context.
 *
 * Falls back to sequential execution when:
 * - pcntl extension is not available
 * - Only one tool needs to be executed
 * - spatie/fork is not installed
 */
class ParallelToolNode extends ToolNode
{
    /**
     * @throws InspectorException
     * @throws ToolException
     * @throws ToolMaxTriesException
     * @throws \Throwable
     */
    protected function executeTools(ToolCallMessage $toolCallMessage, AgentState $state): ToolResultMessage
    {
        // Fallback to sequential execution if pcntl is not available (e.g., Windows)
        if (!\extension_loaded('pcntl')) {
            return parent::executeTools($toolCallMessage, $state);
        }

        // Fallback to sequential execution if spatie/fork is not installed
        if (!\class_exists(Fork::class)) {
            return parent::executeTools($toolCallMessage, $state);
        }

        $toolCallResult = new ToolResultMessage($toolCallMessage->getTools());
        $tools = $toolCallResult->getTools();

        // If there's only one tool, no need for concurrency
        if (\count($tools) === 1) {
            $this->executeSingleTool($tools[0], $state);
            return $toolCallResult;
        }

        // Check max tries and notify before execution
        foreach ($tools as $tool) {
            $state->incrementToolAttempt($tool->getName());

            // Single tool max tries have the highest priority over the global max tries
            $maxTries = $tool->getMaxTries() ?? $this->maxTries;
            if ($state->getToolAttempts($tool->getName()) > $maxTries) {
                throw new ToolMaxTriesException("Tool {$tool->getName()} has been attempted too many times: {$maxTries} attempts.");
            }

            $this->emit('tool-calling', new ToolCalling($tool));
        }

        // Execute tools concurrently and collect serialized tool states
        $serializedTools = Fork::new()->run(
            ...\array_map(
                fn (ToolInterface $tool): \Closure => function () use ($tool): string {
                    try {
                        // Execute the tool - this mutates the tool's internal state
                        $tool->execute();

                        // Serialize the entire tool object with its new state
                        return \serialize($tool);
                    } catch (\Throwable $exception) {
                        // Wrap the exception info with the tool for proper error handling
                        return \serialize([
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
            $data = \unserialize($serializedTool);

            // Check if this is an error response
            if (\is_array($data) && isset($data['error']) && $data['error'] === true) {
                $exceptionClass = $data['exception_class'];
                $exception = null;

                // Recreate the exception
                if (\class_exists($exceptionClass) && \is_subclass_of($exceptionClass, \Throwable::class)) {
                    $exception = new $exceptionClass($data['exception_message'], (int) $data['exception_code']);
                } else {
                    $exception = new ToolException($data['exception_message'], (int) $data['exception_code']);
                }

                $this->emit('error', new AgentError($exception));
                throw $exception;
            }

            // Collect the executed tool with its new state
            $executedTools[$index] = $data;

            // Notify that tool was called successfully
            $this->emit('tool-called', new ToolCalled($data));
        }

        // Return a new ToolCallResultMessage with the executed tools
        return new ToolResultMessage($executedTools);
    }
}
