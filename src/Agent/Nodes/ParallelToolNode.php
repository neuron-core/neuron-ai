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
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolInterface;
use Spatie\Fork\Fork;
use Closure;
use Throwable;

use function array_map;
use function array_values;
use function class_exists;
use function count;
use function extension_loaded;
use function is_array;
use function is_subclass_of;
use function ksort;
use function serialize;
use function unserialize;
use function array_keys;

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
            return yield from parent::executeTools($toolCallMessage, $state);
        }

        // Fallback to sequential execution if spatie/fork is not installed
        if (!class_exists(Fork::class)) {
            return yield from parent::executeTools($toolCallMessage, $state);
        }

        $tools = $toolCallMessage->getTools();

        // If there's only one tool, no need for concurrency
        if (count($tools) === 1) {
            return yield from parent::executeTools($toolCallMessage, $state);
        }

        // Partition: rejected tools are skipped — their rejection result was set by the
        // ToolApproval middleware (ADR 0002/0003: a tool runs iff explicitly approved).
        // Only runnable tools enter the concurrent batch; rejected tools keep their
        // original positions in the result message.
        $rejectedTools = [];
        $runnable = [];
        foreach ($tools as $index => $tool) {
            if ($tool->getApprovalState() === ApprovalState::Rejected) {
                $rejectedTools[$index] = $tool;
            } else {
                $runnable[$index] = $tool;
            }
        }

        // Notify and stream tool-call signals up-front for ALL tools. These are pure
        // stream artifacts and safe to re-emit when the node replays.
        foreach ($tools as $tool) {
            $this->emit(new ToolCalling($tool, true));

            yield new ToolCallChunk($tool);
        }

        // Rejected tools already carry their rejection result.
        $executedTools = $rejectedTools;

        if ($runnable !== []) {
            $runnableTools = array_values($runnable);
            $runnableKeys = array_keys($runnable);

            // Execute the batch durably. Run-count accounting, the max-runs guard,
            // and the concurrent execution all live inside the memo so they happen
            // at most once: on replay the cached serialized tool states are returned
            // and the tools are NOT re-invoked — side-effecting tools (emails,
            // payments, ...) stay at-most-once across crash recovery.
            $serializedTools = $this->memoize('parallel.tools', function () use ($runnableTools, $state): array {
                // Check max tries before execution
                foreach ($runnableTools as $tool) {
                    $key = $tool->getRunKey();

                    $state->incrementToolRun($key);

                    // Single tool max tries have the highest priority over the global max tries
                    $maxTries = $tool->getMaxRuns() ?? $this->maxRuns;
                    if ($state->getToolRuns($key) > $maxTries) {
                        throw new ToolRunsExceededException("Tool {$tool->getName()} has been attempted too many times: {$maxTries} attempts.");
                    }
                }

                // Execute tools concurrently and collect serialized tool states
                return Fork::new()->run(
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
                        $runnableTools
                    )
                );
            });

            // Unserialize and merge the executed tools back into their original positions.
            foreach ($serializedTools as $pos => $serializedTool) {
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
                    $originalTool = $runnableTools[$pos];
                    $this->handleError($exception, $originalTool);
                    $executedTools[$runnableKeys[$pos]] = $originalTool;
                } else {
                    // Collect the executed tool with its new state
                    $executedTools[$runnableKeys[$pos]] = $data;
                }
            }
        }

        // Stream results and notify completion in the original tool order (rejected
        // tools interleave with executed ones at their natural positions).
        ksort($executedTools);

        foreach ($executedTools as $tool) {
            yield new ToolResultChunk($tool);

            $this->emit(new ToolCalled($tool));
        }

        // Return a new ToolCallResultMessage with the executed tools in original order
        return new ToolResultMessage(array_values($executedTools));
    }
}
