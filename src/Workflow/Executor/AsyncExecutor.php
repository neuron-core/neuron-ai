<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

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
        $futures = [];
        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

            // executeBranch() is a live generator; a fiber has no consumer to
            // yield into, so each branch buffers its streamed events and the
            // parent re-emits them after the await.
            $futures[$branchId] = async(function () use (
                $workflow,
                $branchId,
                $branchEvent,
                $forkStepId,
            ): BranchResult {
                $streamedEvents = [];
                $branch = $this->executeBranch($workflow, $branchId, $branchEvent, $forkStepId);
                foreach ($branch as $streamedEvent) {
                    $streamedEvents[] = $streamedEvent;
                }
                $terminal = $branch->getReturn();

                return new BranchResult(
                    result: $terminal instanceof StopEvent ? $terminal->getResult() : null,
                    streamedEvents: $streamedEvents,
                    interrupt: $terminal instanceof InterruptEvent ? $terminal : null,
                );
            });
        }

        $requests = [];
        $firstError = null;

        // Await ALL futures before propagating any exception,
        // otherwise un-awaited futures trigger UnhandledFutureError on destruction.
        foreach ($futures as $branchId => $future) {
            try {
                $result = $future->await();
                if ($result->interrupt instanceof InterruptEvent) {
                    array_push($requests, ...$result->interrupt->requests);
                } else {
                    $parallelEvent->setResult($branchId, $result->result);
                }

                foreach ($result->streamedEvents as $streamedEvent) {
                    yield $streamedEvent;
                }
            } catch (Throwable $e) {
                $firstError ??= $e;
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
}
