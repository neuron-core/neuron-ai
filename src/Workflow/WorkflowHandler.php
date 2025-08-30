<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;

class WorkflowHandler
{
    private mixed $result = null;

    public function __construct(
        protected Workflow $workflow,
        protected WorkflowState $state,
        protected bool $resume = false,
        protected mixed $externalFeedback = null
    ) {
    }

    /**
     * @throws \Throwable
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    public function streamEvents(): \Generator
    {
        $generator = $this->resume ? $this->workflow->run() : $this->workflow->resume($this->externalFeedback);

        while ($generator->valid()) {
            $current = $generator->current();

            if ($current instanceof Event) {
                yield $current;
            }

            $generator->next();
        }

        // Store the final result
        $this->result = $generator->getReturn();
    }

    /**
     * @throws \Throwable
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    public function getResult(): WorkflowState
    {
        // If streaming hasn't been consumed, consume it silently to get the final result
        if ($this->result === null) {
            /*foreach ($this->streamEvents() as $event) {
                continue;
            }*/
            $generator = $this->streamEvents();
            \iterator_to_array($generator, false); // Consume all yielded values
            $this->result = $generator->getReturn(); // Gets the returned value
        }

        return $this->result;
    }
}
