<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterface;

use NeuronAI\Workflow\WorkflowState;
use function Amp\async;
use function Amp\Future\await;

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
     */
    protected function executeParallelBranches(
        WorkflowInterface $workflow,
        ParallelEvent $parallelEvent
    ): Generator {
        $futures = [];
        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            $futures[$branchId] = async(
                fn (): BranchResult => $this->executeBranch($workflow, $branchId, $branchEvent)
            );
        }

        /** @var array<string, BranchResult> $branchResults */
        $branchResults = await($futures);

        foreach ($branchResults as $branchId => $result) {
            foreach ($result->stateChanges as $key => $value) {
                $workflow->resolveState()->set("branches.{$branchId}.{$key}", $value);
            }

            foreach ($result->streamedEvents as $streamedEvent) {
                yield $streamedEvent;
            }
        }

        return $parallelEvent;
    }

    protected function buildNodeStartEvent(string $currentNode, WorkflowState $state): WorkflowNodeStart
    {
        return new WorkflowNodeStart($currentNode, $state, true);
    }
}
