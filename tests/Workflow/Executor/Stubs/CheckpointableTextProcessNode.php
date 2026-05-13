<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class CheckpointableTextProcessNode extends Node
{
    public bool $closureReExecuted = false;

    public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
    {
        $result = $this->checkpoint('expensive_computation', function (): string {
            $this->closureReExecuted = true;
            return 'computed_value';
        });

        $state->set('checkpoint_result', $result);

        $this->interrupt(
            new ApprovalRequest(
                'text branch needs approval',
                [new Action('approve_text', 'Approve', 'Approve text processing')],
            )
        );

        $state->set('text_resumed', true);

        return new StopEvent(result: 'CHECKPOINT_APPROVED');
    }
}
