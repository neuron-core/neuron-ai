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

        $calls = $toolCallMessage->getToolCalls();

        // If there's only one tool, no need for concurrency
        if (count($calls) === 1) {
            return yield from parent::executeTools($toolCallMessage, $state);
        }

        // Partition: rejected calls are skipped — their rejection result was set by the
        // approval flow (a tool runs iff explicitly approved).
        // Only runnable calls enter the concurrent batch; rejected calls keep their
        // original positions in the result message.
        $rejectedCalls = [];
        $runnable = [];
        foreach ($calls as $index => $call) {
            if ($call->getApprovalState() === ApprovalState::Rejected) {
                $rejectedCalls[$index] = $call;
            } else {
                $runnable[$index] = $call;
            }
        }

        // Notify and stream tool-call signals up-front for ALL calls. These are pure
        // stream artifacts and safe to re-emit when the node replays.
        foreach ($calls as $call) {
            $this->emit(new ToolCalling($call, true));

            yield new ToolCallChunk($call);
        }

        // Rejected calls already carry their rejection result.
        $executedCalls = $rejectedCalls;

        if ($runnable !== []) {
            $runnableCalls = array_values($runnable);
            $runnableKeys = array_keys($runnable);

            // Execute the batch durably. Resolution against the live registry,
            // run-count accounting, the max-runs guard, and the concurrent execution
            // all live inside the memo so they happen at most once: on replay the
            // cached serialized results are returned and the tools are NOT
            // re-invoked — side-effecting tools (emails, payments, ...) stay
            // at-most-once across crash recovery.
            $serializedResults = $this->memoize('parallel.tools', function () use ($runnableCalls, $state): array {
                // Resolve every call against the live registry (parent-side) and
                // apply the run guards before forking.
                $resolved = [];
                foreach ($runnableCalls as $pos => $call) {
                    $tool = $this->resolveTool($call);

                    $key = $tool->getRunKey();

                    $state->incrementToolRun($key);

                    // Single tool max tries have the highest priority over the global max tries
                    $maxTries = $tool->getMaxRuns() ?? $this->maxRuns;
                    if ($state->getToolRuns($key) > $maxTries) {
                        throw new ToolRunsExceededException("Tool {$call->getName()} has been attempted too many times: {$maxTries} attempts.");
                    }

                    $resolved[$pos] = $tool;
                }

                // Fork children return the serialized RESULT only — the
                // tool object and its dependencies never cross the process boundary.
                return Fork::new()->run(
                    ...array_map(
                        fn (ToolInterface $tool): Closure => function () use ($tool): string {
                            try {
                                $tool->execute();

                                return serialize($tool->getResult());
                            } catch (Throwable $exception) {
                                // Wrap the exception info for proper error handling
                                return serialize([
                                    'error' => true,
                                    'exception_class' => $exception::class,
                                    'exception_message' => $exception->getMessage(),
                                    'exception_code' => $exception->getCode(),
                                    'tool_name' => $tool->getName(),
                                ]);
                            }
                        },
                        $resolved
                    )
                );
            });

            // Settle each result onto its call, back in the original positions.
            foreach ($serializedResults as $pos => $serializedResult) {
                $data = unserialize($serializedResult);
                $call = $runnableCalls[$pos];

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

                    $this->handleError($exception, $call);
                } else {
                    $call->setResult($data);
                }

                $executedCalls[$runnableKeys[$pos]] = $call;
            }
        }

        // Stream results and notify completion in the original call order (rejected
        // calls interleave with executed ones at their natural positions).
        ksort($executedCalls);

        foreach ($executedCalls as $call) {
            yield new ToolResultChunk($call);

            $this->emit(new ToolCalled($call));
        }

        // Return a new ToolCallResultMessage with the settled calls in original order
        return new ToolResultMessage(array_values($executedCalls));
    }
}
