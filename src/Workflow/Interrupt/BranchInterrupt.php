<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use NeuronAI\Exceptions\WorkflowException;

/**
 * Internal exception thrown when a WorkflowInterrupt occurs inside a parallel branch.
 *
 * Wraps the original WorkflowInterrupt and carries the branch ID so that
 * executeParallelBranches() can build a parallel-aware WorkflowInterrupt
 * with the main (non-cloned) workflow state.
 *
 * This exception is never persisted or exposed to user code.
 */
class BranchInterrupt extends WorkflowException
{
    public function __construct(
        public readonly string $branchId,
        public readonly WorkflowInterrupt $original,
    ) {
        parent::__construct(
            "WorkflowInterrupt in branch '{$branchId}': " . $original->getMessage(),
            0,
            $original
        );
    }
}
