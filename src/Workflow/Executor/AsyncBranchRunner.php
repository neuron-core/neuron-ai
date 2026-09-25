<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Amp\Future;
use Generator;
use NeuronAI\Workflow\Events\ParallelEvent;
use Throwable;

use function Amp\async;
use function array_keys;

/**
 * Runs the branches of a fork concurrently as Amp futures. Once a branch
 * pauses or fails, the others start no new node, but finish the one they are
 * running, stream and memo writes included.
 *
 * Nodes are shared by the branches, so two concurrent branches must not reach
 * the same node: give each branch its own event and node.
 */
class AsyncBranchRunner implements BranchRunner
{
    /**
     * @throws Throwable
     */
    public function run(Segment $segment, ParallelEvent $fork, string $forkStepId): Generator
    {
        /** @var array<string, Generator<int, object, mixed, bool>> $branches */
        $branches = [];
        $futures = [];
        foreach (array_keys($fork->branches) as $branchId) {
            if ($fork->hasResult($branchId)) {
                continue;
            }

            $branch = $segment->branch($fork, $branchId, $forkStepId);
            $branches[$branchId] = $branch;
            $futures[$branchId] = async(
                fn (): BranchResult => $this->advanceBranch($branch),
            );
        }

        $paused = false;
        $firstError = null;

        // Drain every branch before propagating an exception so no failed
        // future is left unobserved.
        while ($futures !== []) {
            foreach (Future::iterate($futures) as $branchId => $future) {
                unset($futures[$branchId]);

                try {
                    $result = $future->await();
                    if ($result->streamedEvent !== null) {
                        yield $result->streamedKey => $result->streamedEvent;
                        $branch = $branches[$branchId];
                        $futures[$branchId] = async(
                            fn (): BranchResult => $this->advanceBranch($branch, true),
                        );
                        continue;
                    }

                    if ($result->paused) {
                        $paused = true;
                    }
                } catch (Throwable $e) {
                    $firstError ??= $e;
                }
            }
        }

        if ($firstError instanceof Throwable) {
            throw $firstError;
        }

        return $paused;
    }

    /**
     * @param Generator<int, object, mixed, bool> $branch
     */
    protected function advanceBranch(Generator $branch, bool $resume = false): BranchResult
    {
        if ($resume) {
            $branch->next();
        }

        if ($branch->valid()) {
            return new BranchResult(streamedEvent: $branch->current(), streamedKey: $branch->key());
        }

        return new BranchResult(paused: !$branch->getReturn());
    }
}
