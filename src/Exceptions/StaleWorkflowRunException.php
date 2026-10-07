<?php

declare(strict_types=1);

namespace NeuronAI\Exceptions;

use NeuronAI\Workflow\RefusalReason;

class StaleWorkflowRunException extends WorkflowRefusedException
{
    public function __construct(
        public readonly string $workflowId,
        public readonly string $expectedRunId,
        public readonly ?string $actualRunId,
    ) {
        $actual = $this->actualRunId ?? 'none';

        parent::__construct(
            "Stale continuation for workflow ID '{$this->workflowId}': "
            . "expected run '{$this->expectedRunId}', current run is '{$actual}'.",
            RefusalReason::StaleRun,
        );
    }
}
