<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Exceptions\ToolMaxTriesException;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use Spatie\Fork\Fork;

/**
 * Implemented using spatie/fork
 *
 * For more information, you can check the GitHub repository of the package:
 * https://github.com/spatie/fork
 *
 * It requires the pcntl extension which is installed in many Unix and Mac systems by default.
 *
 * ❗️ pcntl only works in CLI processes, not in a web context.
 *
 * @phpstan-ignore trait.unused
 */
trait ParallelToolCalls
{
    protected function executeTools(ToolCallMessage $toolCallMessage): ToolCallResultMessage
    {
        // Fallback to the original implementation if pcntl is not available (e.g. Windows).
        if (!\extension_loaded('pcntl')) {
            return parent::executeTools($toolCallMessage);
        }

        $toolCallResult = new ToolCallResultMessage($toolCallMessage->getTools());
        $tools = $toolCallResult->getTools();

        // If there's only one tool, no need for concurrency
        if (\count($tools) === 1) {
            $this->executeSingleTool($tools[0]);
            return $toolCallResult;
        }

        // Check max tries and notify before execution
        foreach ($tools as $tool) {
            $this->toolAttempts[$tool->getName()] = ($this->toolAttempts[$tool->getName()] ?? 0) + 1;

            // Single tool max tries have the highest priority over the global max tries
            $maxTries = $tool->getMaxTries() ?? $this->tollMaxTries;
            if ($this->toolAttempts[$tool->getName()] > $maxTries) {
                throw new ToolMaxTriesException("Tool {$tool->getName()} has been attempted too many times: {$maxTries} attempts.");
            }

            $this->notify('tool-calling', new ToolCalling($tool));
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

                $this->notify('error', new AgentError($exception));
                throw $exception;
            }

            // Collect the executed tool with its new state
            $executedTools[$index] = $data;

            // Notify that tool was called successfully
            $this->notify('tool-called', new ToolCalled($data));
        }

        // Return a new ToolCallResultMessage with the executed tools
        return new ToolCallResultMessage($executedTools);
    }
}
