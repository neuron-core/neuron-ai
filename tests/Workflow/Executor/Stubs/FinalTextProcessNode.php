<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class FinalTextProcessNode extends Node
{
    public function __invoke(Step3Event $event, WorkflowState $state): StopEvent
    {
        $state->set('final_step_executed', true);
        return new StopEvent(result: 'MULTI_STEP_COMPLETE');
    }
}
