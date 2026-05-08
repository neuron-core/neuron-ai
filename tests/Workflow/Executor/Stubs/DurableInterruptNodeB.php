<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\WorkflowState;

class DurableInterruptNodeB extends CountableNode
{
    public function __invoke(DurableEventA $event, WorkflowState $state): DurableEventB|StopEvent
    {
        $this->recordExecution();
        $state->set('step_b_executed', true);

        $resumeRequest = $this->consumeResumeRequest();
        if ($resumeRequest instanceof ApprovalRequest) {
            $state->set('step_b_resumed', true);
            return new DurableEventB();
        }

        $this->interrupt(
            new ApprovalRequest(
                'durable interrupt at step B',
                [new Action('approve_b', 'Approve', 'Approve step B')],
            )
        );

        $state->set('step_b_post_interrupt', true);
        return new DurableEventB();
    }
}
