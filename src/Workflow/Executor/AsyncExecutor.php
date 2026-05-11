<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Interrupt\BranchInterrupt;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
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
     * @return Generator<int, ParallelEvent, mixed, ParallelEvent>
     * @throws WorkflowInterrupt
     */
    protected function executeBranches(
        WorkflowInterface $workflow,
        ParallelEvent $parallelEvent,
        ?WorkflowInterrupt $interrupt = null,
        ?InterruptRequest $resumeRequest = null,
    ): Generator {
        $futures = [];
        foreach ($parallelEvent->branches as $branchId => $branchEvent) {
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

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
        $firstError = null;

        // Await ALL futures before propagating any exception,
        // otherwise un-awaited futures trigger UnhandledFutureError on destruction.
        foreach ($futures as $branchId => $future) {
            try {
                $result = $future->await();
                $parallelEvent->setResult($branchId, $result->result);

                foreach ($result->streamedEvents as $streamedEvent) {
                    yield $streamedEvent;
                }
            } catch (BranchInterrupt $branchInterrupt) {
                if (!$firstBranchInterrupt instanceof BranchInterrupt) {
                    $firstBranchInterrupt = $branchInterrupt;
                }
            } catch (Throwable $e) {
                $firstError ??= $e;
            }
        }

        if ($firstError instanceof Throwable) {
            throw $firstError;
        }

        if ($firstBranchInterrupt instanceof BranchInterrupt) {
            throw new WorkflowInterrupt(
                request: $firstBranchInterrupt->original->getRequest(),
                node: $firstBranchInterrupt->original->getNode(),
                state: $workflow->resolveState(),
                event: $firstBranchInterrupt->original->getEvent(),
                branchId: $firstBranchInterrupt->branchId,
                parallelEvent: $parallelEvent,
                completedBranchResults: $parallelEvent->getAllResults(),
            );
        }

        return $parallelEvent;
    }
}
