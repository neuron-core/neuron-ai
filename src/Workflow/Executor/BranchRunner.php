<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Events\ParallelEvent;

/**
 * How the branches of a fork run. The segment keeps the durable protocol of
 * every branch; a runner decides only when each one runs.
 */
interface BranchRunner
{
    /**
     * Run the fork's unfinished branches through the segment, passing on
     * what they stream, keys included.
     *
     * @return Generator<int, object, mixed, bool> Whether a branch paused.
     */
    public function run(Segment $segment, ParallelEvent $fork, string $forkStepId): Generator;
}
