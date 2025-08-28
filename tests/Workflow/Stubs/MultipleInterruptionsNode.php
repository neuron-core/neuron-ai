<?php

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

class MultipleInterruptionsNode extends Node
{
    /**
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    public function run(Event $event, WorkflowState $state): SecondEvent
    {
        $interruptCount = $state->get('interrupt_count', 0);
        $state->set('interrupt_count', ++$interruptCount);

        if ($state->get('interrupt_count') < 3) {
            $this->interrupt(['count' => $interruptCount, 'message' => "Interrupt #{$interruptCount}"]);
        }

        $state->set('all_interrupts_complete', true);
        return new SecondEvent('All interrupts complete');
    }
}
