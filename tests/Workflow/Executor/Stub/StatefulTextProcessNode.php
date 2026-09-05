<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class StatefulTextProcessNode extends Node
{
    public static int $executions = 0;

    public function __invoke(TextProcessEvent $event, WorkflowState $state): SecondTextProcessEvent
    {
        self::$executions++;
        $state->set('branch_value', 42);

        return new SecondTextProcessEvent();
    }
}
