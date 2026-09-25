<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Events\ParallelEvent;

use function array_keys;

/** Runs the branches of a fork one after another, up to the first pause. */
class SequentialBranchRunner implements BranchRunner
{
    public function run(Segment $segment, ParallelEvent $fork, string $forkStepId): Generator
    {
        $paused = false;
        foreach (array_keys($fork->branches) as $branchId) {
            if ($segment->shouldPause()) {
                return true;
            }
            if ($fork->hasResult($branchId)) {
                continue;
            }

            $completed = yield from $segment->branch($fork, $branchId, $forkStepId);
            if (!$completed) {
                $paused = true;
            }
        }

        return $paused;
    }
}
