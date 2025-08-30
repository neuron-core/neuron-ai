<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;

class WorkflowHandler
{
    protected WorkflowState $result;

    public function __construct(
        protected Workflow $workflow,
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
        $generator = $this->resume ? $this->workflow->resume($this->externalFeedback) : $this->workflow->run();

        while ($generator->valid()) {
            yield $generator->current();
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
        if (!isset($this->result)) {
            foreach ($this->streamEvents() as $event) {
            }
        }

        return $this->result;
    }
}
