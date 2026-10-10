<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Closure;
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Agent\Observability\ToolCalling;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolRegistry;
use Spatie\Fork\Fork;
use Throwable;

use function array_map;
use function array_diff_key;
use function array_values;
use function class_exists;
use function count;
use function function_exists;
use function is_array;
use function ksort;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_signal_get_handler;
use function serialize;
use function unserialize;
use function array_keys;

use const SIGINT;
use const SIGQUIT;
use const SIGTERM;

class ParallelToolNode extends ToolNode
{
    protected ?Closure $beforeChild;

    protected ?Closure $afterChild;

    public function __construct(
        int $maxRuns = 10,
        ?callable $errorHandler = null,
        ?callable $beforeChild = null,
        ?callable $afterChild = null,
    ) {
        parent::__construct($maxRuns, $errorHandler);

        $this->beforeChild = $beforeChild !== null
            ? Closure::fromCallable($beforeChild)
            : null;
        $this->afterChild = $afterChild !== null
            ? Closure::fromCallable($afterChild)
            : null;
    }

    /**
     * @param array<int, ToolCall> $calls
     * @param string $messageId The ToolCallMessage holding the calls, carried by their chunks.
     * @return Generator<int, ToolCallChunk|ToolResultChunk, mixed, array<int, ToolCall>>
     * @throws ToolException
     * @throws ToolRunsExceededException
     * @throws Throwable
     */
    protected function executeLocalTools(array $calls, string $messageId, AgentState $state, ToolRegistry $tools): Generator
    {
        // Sequential fallbacks: forking unavailable (Windows, or the pcntl functions
        // PHP-FPM disables on Debian and Ubuntu), no posix_kill (spatie/fork then
        // ends a child with exit(), which destroys the connections it inherited and
        // closes them for the parent too), spatie/fork not installed, or a single
        // call not worth forking for.
        if (!function_exists('pcntl_fork')) {
            return yield from parent::executeLocalTools($calls, $messageId, $state, $tools);
        }

        if (!function_exists('posix_kill')) {
            return yield from parent::executeLocalTools($calls, $messageId, $state, $tools);
        }

        if (!class_exists(Fork::class)) {
            return yield from parent::executeLocalTools($calls, $messageId, $state, $tools);
        }

        $calls = array_diff_key($calls, $this->filterDeferredCalls($calls));

        if (count($calls) <= 1) {
            return yield from parent::executeLocalTools($calls, $messageId, $state, $tools);
        }

        // Only runnable calls enter the concurrent batch; rejected calls
        // already carry their rejection result and keep their original
        // positions in the result message.
        $rejectedCalls = [];
        $runnable = [];
        foreach ($calls as $index => $call) {
            if ($call->getApprovalState() === ApprovalState::Rejected) {
                $rejectedCalls[$index] = $call;
            } else {
                $runnable[$index] = $call;
            }
        }

        // Tool-call signals go out up-front for ALL calls: pure stream
        // artifacts, safe to re-emit when the node replays.
        foreach ($calls as $call) {
            $this->emit(new ToolCalling($call, true));

            yield new ToolCallChunk($messageId, $call);
        }

        $executedCalls = $rejectedCalls;

        // Restore accounting even when the batch's execution results are cached.
        // All calls reserve their slots in the parent before any child starts;
        // a call the accounting refuses is settled here, as in sequential mode.
        foreach ($runnable as $index => $call) {
            try {
                $this->checkToolRuns($call, $state, $tools);
            } catch (Throwable $e) {
                $this->handleError($e, $call);
                $executedCalls[$index] = $call;
                unset($runnable[$index]);
            }
        }

        if ($runnable !== []) {
            $runnableCalls = array_values($runnable);
            $runnableKeys = array_keys($runnable);

            $serializedResults = $this->memoize('parallel.tools', function () use ($runnableCalls, $tools): array {
                // Resolve parent-side, before forking.
                $resolved = [];
                foreach ($runnableCalls as $pos => $call) {
                    $resolved[$pos] = $this->resolveTool($call, $tools);
                }

                // Fork children return the serialized RESULT only — the
                // tool object and its dependencies never cross the process boundary.
                $beforeChild = $this->beforeChild;
                $afterChild = $this->afterChild;
                return $this->runInChildProcesses(
                    array_map(
                        fn (ToolInterface $tool): Closure => function () use ($tool, $beforeChild, $afterChild): string {
                            try {
                                if ($beforeChild instanceof Closure) {
                                    $beforeChild();
                                }

                                try {
                                    $tool->execute();
                                } finally {
                                    if ($afterChild instanceof Closure) {
                                        $afterChild();
                                    }
                                }

                                return serialize($tool->getResult());
                            } catch (Throwable $exception) {
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

            foreach ($serializedResults as $pos => $serializedResult) {
                $data = unserialize($serializedResult);
                $call = $runnableCalls[$pos];

                if (is_array($data) && isset($data['error']) && $data['error'] === true) {
                    // The original exception lives in the child process: report what it was.
                    $this->handleError(new ToolException(
                        "Tool {$data['tool_name']} failed with {$data['exception_class']}: {$data['exception_message']}",
                        (int) $data['exception_code'],
                    ), $call);
                } else {
                    $call->setResult($data);
                }

                $executedCalls[$runnableKeys[$pos]] = $call;
            }
        }

        // Results go out in the original call order: rejected calls
        // interleave with executed ones at their natural positions.
        ksort($executedCalls);

        foreach ($executedCalls as $call) {
            yield new ToolResultChunk($call);

            $this->emit(new ToolCalled($call));
        }

        return $executedCalls;
    }

    /**
     * spatie/fork replaces the SIGTERM, SIGINT and SIGQUIT handlers with its
     * own, which kill the process, and never restores them: a queue worker
     * would lose its graceful shutdown for good after one parallel batch.
     *
     * @param array<int, Closure(): string> $tasks
     * @return array<int, string>
     */
    protected function runInChildProcesses(array $tasks): array
    {
        $handlers = [];
        foreach ([SIGINT, SIGQUIT, SIGTERM] as $signal) {
            $handlers[$signal] = pcntl_signal_get_handler($signal);
        }
        $asyncSignals = pcntl_async_signals();

        try {
            return Fork::new()->run(...$tasks);
        } finally {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($asyncSignals);
        }
    }
}
