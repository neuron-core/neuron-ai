<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use Generator;
use Throwable;

class WorkflowHandler implements WorkflowHandlerInterface
{
    protected WorkflowState $result;

    public function __construct(
        protected Workflow $workflow,
        protected ?InterruptRequest $resumeRequest = null
    ) {
    }

    /**
     * Stream workflow events, optionally through a protocol adapter.
     *
     * @param StreamAdapterInterface|null $adapter Optional protocol adapter
     * @throws Throwable
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    public function events(?StreamAdapterInterface $adapter = null): Generator
    {
        // Protocol start (if adapter provided)
        if ($adapter instanceof StreamAdapterInterface) {
            foreach ($adapter->start() as $output) {
                yield $output;
            }
        }

        // Stream events
        $generator = $this->resumeRequest instanceof InterruptRequest
            ? $this->workflow->resume($this->resumeRequest)
            : $this->workflow->run();

        while ($generator->valid()) {
            $event = $generator->current();

            // Transform through adapter or yield raw event
            if ($adapter instanceof StreamAdapterInterface) {
                foreach ($adapter->transform($event) as $output) {
                    yield $output;
                }
            } else {
                yield $event;
            }

            $generator->next();
        }

        // Store the final result
        $this->result = $generator->getReturn();

        // Protocol end (if adapter provided)
        if ($adapter instanceof StreamAdapterInterface) {
            foreach ($adapter->end() as $output) {
                yield $output;
            }
        }

        return $this->result;
    }

    /**
     * @throws Throwable
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    public function run(): WorkflowState
    {
        // If streaming hasn't been consumed, consume it silently to get the final result
        if (!isset($this->result)) {
            foreach ($this->events() as $event) {
            }
        }

        return $this->result;
    }
}
