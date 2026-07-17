<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor\Amp;

use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Executor\BranchResult;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\WorkflowInterface;
use Throwable;

use function Amp\async;

/**
 * Executor that runs parallel branches concurrently using Amp fibers.
 *
 * Regular nodes execute sequentially as usual; branches from any node
 * returning ParallelEvent execute as concurrent Amp futures.
 */
class AsyncExecutor extends WorkflowExecutor
{
    /**
     * Override to run branches as concurrent Amp futures.
     *
     * @return Generator<int, Event, mixed, ParallelEvent|InterruptEvent>
     * @throws Throwable
     */
    protected function executeBranches(
        WorkflowInterface $workflow,
        ParallelEvent $parallelEvent,
    ): Generator {
        $futures = [];
        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

            $futures[$branchId] = async(
                fn (): BranchResult => $this->executeBranch($workflow, $branchId, $branchEvent)
            );
        }

        $firstInterrupt = null;
        $firstError = null;

        // Await ALL futures before propagating any exception,
        // otherwise un-awaited futures trigger UnhandledFutureError on destruction.
        foreach ($futures as $branchId => $future) {
            try {
                $result = $future->await();
                if ($result->interrupt instanceof InterruptEvent) {
                    $firstInterrupt ??= $result->interrupt;
                } else {
                    $parallelEvent->setResult($branchId, $result->result);

                    foreach ($result->streamedEvents as $streamedEvent) {
                        yield $streamedEvent;
                    }
                }
            } catch (Throwable $e) {
                $firstError ??= $e;
            }
        }

        if ($firstError instanceof Throwable) {
            throw $firstError;
        }

        if ($firstInterrupt instanceof InterruptEvent) {
            return $firstInterrupt;
        }

        return $parallelEvent;
    }
}
