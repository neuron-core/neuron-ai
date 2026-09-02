<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Amp\Future;
use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowRuntimeInterface;
use Throwable;

use function Amp\async;
use function array_push;

/**
 * Executor that runs parallel branches concurrently using Amp fibers.
 *
 * Regular nodes execute sequentially as usual; branches from any node
 * returning ParallelEvent execute as concurrent Amp futures.
 */
class AsyncExecutor extends WorkflowExecutor
{
    /**
     * @param WorkflowMiddleware[] $middleware
     * @return Generator<int, Event, mixed, Event>
     */
    protected function runNode(
        NodeInterface $node,
        NodeContext $context,
        array $middleware = [],
        ?string $branchId = null,
    ): Generator {
        return yield from parent::runNode(
            $branchId === null ? $node : clone $node,
            $context,
            $middleware,
            $branchId,
        );
    }

    /**
     * Override to run branches as concurrent Amp futures.
     *
     * @return Generator<int, Event, mixed, ParallelEvent|InterruptEvent>
     * @throws Throwable
     */
    protected function executeBranches(
        WorkflowRuntimeInterface $workflow,
        ParallelEvent $parallelEvent,
        string $forkStepId,
    ): Generator {
        /** @var array<string, Generator<int, Event, mixed, Event>> $branches */
        $branches = [];
        $futures = [];
        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

            $branch = $this->executeBranch($workflow, $branchId, $branchEvent, $forkStepId);
            $branches[$branchId] = $branch;
            $futures[$branchId] = async(
                fn (): BranchResult => $this->advanceBranch($branch),
            );
        }

        $requests = [];
        $firstError = null;

        // Drain every branch before propagating an exception so no failed
        // future is left unobserved.
        while ($futures !== []) {
            foreach (Future::iterate($futures) as $branchId => $future) {
                unset($futures[$branchId]);

                try {
                    $result = $future->await();
                    if ($result->streamedEvent instanceof Event) {
                        yield $result->streamedEvent;
                        $branch = $branches[$branchId];
                        $futures[$branchId] = async(
                            fn (): BranchResult => $this->advanceBranch($branch, true),
                        );
                        continue;
                    }

                    if ($result->interrupt instanceof InterruptEvent) {
                        array_push($requests, ...$result->interrupt->requests);
                    } else {
                        $parallelEvent->setResult($branchId, $result->result);
                    }
                } catch (Throwable $e) {
                    $firstError ??= $e;
                }
            }
        }

        if ($firstError instanceof Throwable) {
            throw $firstError;
        }

        if ($requests !== []) {
            return new InterruptEvent($requests);
        }

        return $parallelEvent;
    }

    /**
     * @param Generator<int, Event, mixed, Event> $branch
     */
    protected function advanceBranch(Generator $branch, bool $resume = false): BranchResult
    {
        if ($resume) {
            $branch->next();
        }

        if ($branch->valid()) {
            return new BranchResult(streamedEvent: $branch->current());
        }

        $terminal = $branch->getReturn();

        return new BranchResult(
            result: $terminal instanceof StopEvent ? $terminal->getResult() : null,
            interrupt: $terminal instanceof InterruptEvent ? $terminal : null,
        );
    }
}
