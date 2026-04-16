<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Interrupt\BranchInterrupt;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterface;

use function Amp\async;
use function array_key_exists;

/**
 * Executor that runs parallel branches concurrently using Amp fibers.
 *
 * Drop-in replacement for WorkflowExecutor: regular nodes execute sequentially
 * as usual; branches from any node returning ParallelEvent execute as concurrent
 * Amp futures.
 *
 * Usage:
 *   Workflow::make()->setExecutor(new AsyncExecutor())
 */
class AsyncExecutor extends WorkflowExecutor
{
    /**
     * Override to run branches as concurrent Amp futures instead of sequentially.
     *
     * @return Generator<int, Event, mixed, Event>
     * @throws WorkflowInterrupt
     */
    protected function executeParallelBranches(
        WorkflowInterface $workflow,
        ParallelEvent $parallelEvent,
        ?WorkflowInterrupt $interrupt = null,
        ?InterruptRequest $resumeRequest = null,
    ): Generator {
        $completedResults = $interrupt?->getCompletedBranchResults() ?? [];
        $parallelEvent->branchResults = $completedResults;

        $futures = [];
        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if (array_key_exists($branchId, $completedResults)) {
                continue;
            }

            // When $interrupt is non-null and its branch matches, $isResuming is true
            // and $interrupt is guaranteed non-null for the rest of this iteration.
            $isResuming = ($branchId === $interrupt?->getBranchId());
            $futures[$branchId] = async(
                fn (): BranchResult => $this->executeBranch(
                    $workflow,
                    $branchId,
                    $isResuming ? $interrupt->getEvent() : $branchEvent,
                    $isResuming ? $resumeRequest : null,
                    $isResuming ? $interrupt->getNode() : null,
                )
            );
        }

        $firstBranchInterrupt = null;

        foreach ($futures as $branchId => $future) {
            try {
                $result = $future->await();
                $completedResults[$branchId] = $result->result;
                $parallelEvent->branchResults[$branchId] = $result->result;

                foreach ($result->streamedEvents as $streamedEvent) {
                    yield $streamedEvent;
                }
            } catch (BranchInterrupt $branchInterrupt) {
                if (!$firstBranchInterrupt instanceof BranchInterrupt) {
                    $firstBranchInterrupt = $branchInterrupt;
                }
            }
        }

        if ($firstBranchInterrupt instanceof BranchInterrupt) {
            throw new WorkflowInterrupt(
                request: $firstBranchInterrupt->original->getRequest(),
                node: $firstBranchInterrupt->original->getNode(),
                state: $workflow->resolveState(),
                event: $firstBranchInterrupt->original->getEvent(),
                branchId: $firstBranchInterrupt->branchId,
                parallelEvent: $parallelEvent,
                completedBranchResults: $completedResults,
            );
        }

        $parallelEvent->branchResults = $completedResults;

        return $parallelEvent;
    }
}
