<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class MultiStepTextProcessNode extends Node
{
    public function __invoke(TextProcessEvent $event, WorkflowState $state): Step2Event
    {
        $state->set('multi_step1_executed', true);
        return new Step2Event('step1 done');
    }
}
