<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class ConditionalNode extends Node
{
    public function __invoke(FirstEvent $event, WorkflowState $state): SecondEvent|ThirdEvent
    {
        $state->set('conditional_node_executed', true);
        $condition = $state->get('condition', 'second');

        if ($condition === 'third') {
            return new ThirdEvent('Conditional chose third');
        }

        return new SecondEvent('Conditional chose second');
    }
}
