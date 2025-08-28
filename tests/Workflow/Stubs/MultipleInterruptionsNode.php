<?php

declare(strict_types=1);

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

        if ($state->get('interrupt_count') <= 3) {
            // First interrupt
            $feedback = $this->interrupt(['count' => $interruptCount, 'message' => "Interrupt #{$interruptCount}"]);

            // Second and Third interrupt
            while ($feedback === 0) {
                $feedback = $this->interrupt(['count' => $interruptCount, 'message' => "Interrupt #{$interruptCount}"]);
            }
        }

        $state->set('all_interrupts_complete', true);
        return new SecondEvent('All interrupts complete');
    }
}
