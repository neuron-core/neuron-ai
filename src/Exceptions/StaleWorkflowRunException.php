<?php

declare(strict_types=1);

namespace NeuronAI\Exceptions;

class StaleWorkflowRunException extends WorkflowException
{
    public function __construct(
        public readonly string $workflowId,
        public readonly string $expectedRunId,
        public readonly ?string $actualRunId,
    ) {
        $actual = $this->actualRunId ?? 'none';

        parent::__construct(
            "Stale continuation for workflow ID '{$this->workflowId}': "
            . "expected run '{$this->expectedRunId}', current run is '{$actual}'."
        );
    }
}
