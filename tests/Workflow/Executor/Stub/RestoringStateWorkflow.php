<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

/** @extends Workflow<RestorableState> */
class RestoringStateWorkflow extends Workflow
{
    /** @var list<string> */
    public array $restorations = [];

    protected function state(): WorkflowState
    {
        $state = new RestorableState();
        $state->operation = static fn (): string => 'live';

        return $state;
    }

    public function restoreState(WorkflowState $state): WorkflowState
    {
        if ($state instanceof RestorableState) {
            $this->restorations[] = $state->get('__branchId', 'main') . ($state->get('paused', false) ? ':paused' : '');
            $state->operation = static fn (): string => 'restored';
        }

        return $state;
    }
}
