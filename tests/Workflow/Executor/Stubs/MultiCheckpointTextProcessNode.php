<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class MultiCheckpointTextProcessNode extends Node
{
    public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
    {
        $cp1 = $this->checkpoint('step1', fn (): string => 'step1_done');
        $state->set('cp1', $cp1);

        $cp2 = $this->checkpoint('step2', fn (): string => 'step2_done');
        $state->set('cp2', $cp2);

        $this->interrupt(
            new ApprovalRequest(
                'multi-checkpoint branch needs approval',
                [new Action('approve_text', 'Approve', 'Approve text processing')],
            )
        );

        $state->set('text_resumed', true);

        return new StopEvent(result: [
            'status' => 'MULTI_CHECKPOINT_APPROVED',
            'cp1' => $cp1,
            'cp2' => $cp2,
        ]);
    }
}
