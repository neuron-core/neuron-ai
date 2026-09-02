<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class WorkflowStateTest extends TestCase
{
    public function test_execution_metadata_is_not_application_state(): void
    {
        $state = new WorkflowState(['customer' => 'alice']);

        $state->setExecutionMetadata('workflow-1', 'run-1', 2);

        $this->assertSame('workflow-1', $state->getWorkflowId());
        $this->assertSame('run-1', $state->getRunId());
        $this->assertSame(2, $state->getExecutionAttempt());
        $this->assertSame(['customer' => 'alice'], $state->all());
        $this->assertFalse($state->has('__workflowId'));
        $this->assertFalse($state->has('__runId'));
        $this->assertFalse($state->has('__executionAttempt'));
    }

    public function test_execution_metadata_survives_state_cloning(): void
    {
        $state = new WorkflowState();
        $state->setExecutionMetadata('workflow-1', 'run-1', 2);

        $clone = clone $state;

        $this->assertSame('workflow-1', $clone->getWorkflowId());
        $this->assertSame('run-1', $clone->getRunId());
        $this->assertSame(2, $clone->getExecutionAttempt());
    }
}
