<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Generator;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use Spatie\Fork\Fork;
use Closure;
use Throwable;

use function array_map;
use function array_diff_key;
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
    protected ?Closure $beforeChild;

    protected ?Closure $afterChild;

    public function __construct(
        ChatHistoryInterface $chatHistory,
        int $maxRuns = 10,
        ?callable $errorHandler = null,
        ?callable $beforeChild = null,
        ?callable $afterChild = null,
    ) {
        parent::__construct($chatHistory, $maxRuns, $errorHandler);

        $this->beforeChild = $beforeChild !== null
            ? Closure::fromCallable($beforeChild)
            : null;
        $this->afterChild = $afterChild !== null
            ? Closure::fromCallable($afterChild)
            : null;
    }

    /**
     * @param array<int, ToolCall> $calls
     * @return Generator<int, ToolCallChunk|ToolResultChunk, mixed, array<int, ToolCall>>
     * @throws ToolException
     * @throws ToolRunsExceededException
     * @throws Throwable
     */
    protected function executeLocalTools(array $calls): Generator
    {
        // Sequential fallbacks: pcntl unavailable (e.g. Windows), spatie/fork
        // not installed, or a single call not worth forking for.
        if (!extension_loaded('pcntl')) {
            return yield from parent::executeLocalTools($calls);
        }

        if (!class_exists(Fork::class)) {
            return yield from parent::executeLocalTools($calls);
        }

        $calls = array_diff_key($calls, $this->filterDeferredCalls($calls));

        if (count($calls) <= 1) {
            return yield from parent::executeLocalTools($calls);
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

            yield new ToolCallChunk($call);
        }

        $executedCalls = $rejectedCalls;

        if ($runnable !== []) {
            $runnableCalls = array_values($runnable);
            $runnableKeys = array_keys($runnable);

            // Restore accounting even when the batch's execution results are cached.
            // All calls reserve their slots in the parent before any child starts.
            foreach ($runnable as $index => $call) {
                $this->checkToolRuns($call, $index);
            }

            $serializedResults = $this->memoize('parallel.tools', function () use ($runnableCalls): array {
                // Resolve parent-side, before forking.
                $resolved = [];
                foreach ($runnableCalls as $pos => $call) {
                    $resolved[$pos] = $this->resolveTool($call);
                }

                // Fork children return the serialized RESULT only — the
                // tool object and its dependencies never cross the process boundary.
                $beforeChild = $this->beforeChild;
                $afterChild = $this->afterChild;
                return Fork::new()->run(
                    ...array_map(
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
                    $exceptionClass = $data['exception_class'];
                    $exception = null;

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

        // Results go out in the original call order: rejected calls
        // interleave with executed ones at their natural positions.
        ksort($executedCalls);

        foreach ($executedCalls as $call) {
            yield new ToolResultChunk($call);

            $this->emit(new ToolCalled($call));
        }

        return $executedCalls;
    }
}
