<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Closure;
use Generator;
use Inspector\Exceptions\InspectorException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\EventBus;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

use function array_key_exists;
use function call_user_func;
use function is_callable;

abstract class Node implements NodeInterface
{
    protected WorkflowState $state;
    protected Event $event;
    protected ?InterruptRequest $resumeRequest = null;

    /**
     * @var array<string, mixed>
     */
    protected array $checkpoints = [];

    public function run(Event $event, WorkflowState $state): Generator|Event
    {
        /** @phpstan-ignore method.notFound */
        return $this->__invoke($event, $state);
    }

    public function setWorkflowContext(
        WorkflowState $currentState,
        Event $currentEvent,
        ?InterruptRequest $resumeRequest = null
    ): void {
        $this->state = $currentState;
        $this->event = $currentEvent;
        $this->resumeRequest = $resumeRequest;
    }

    /**
     * Consume the interrupt request (used internally by nodes).
     * Returns null if not resuming or no request provided.
     */
    protected function consumeResumeRequest(): ?InterruptRequest
    {
        if ($this->resumeRequest instanceof InterruptRequest) {
            $request = $this->resumeRequest;
            // Clear the request after use to allow subsequent interrupts
            $this->resumeRequest = null;
            return $request;
        }

        return null;
    }

    protected function checkpoint(string $name, Closure $closure): mixed
    {
        if (array_key_exists($name, $this->checkpoints)) {
            $result = $this->checkpoints[$name];
            unset($this->checkpoints[$name]);
            return $result;
        }

        $result = call_user_func($closure);
        $this->checkpoints[$name] = $result;
        return $result;
    }

    /**
     * @template T of InterruptRequest
     * @param T $request
     * @return T|null
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    protected function interrupt(InterruptRequest $request): ?InterruptRequest
    {
        return $this->interruptIf(true, $request);
    }

    /**
     * @template T of InterruptRequest
     * @param T $request
     * @return T|null
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    protected function interruptIf(callable|bool $condition, InterruptRequest $request): ?InterruptRequest
    {
        if (($feedback = $this->consumeResumeRequest()) instanceof InterruptRequest) {
            return $feedback;
        }

        $shouldInterrupt = is_callable($condition) ? $condition() : $condition;

        if ($shouldInterrupt) {
            throw new WorkflowInterrupt(
                $request,
                $this,
                $this->state,
                $this->event
            );
        }

        // Condition didn't meet, continue execution
        return null;
    }

    /**
     * Check if the node is in resuming mode.
     *
     * This is useful for middleware to determine if the workflow is resuming
     * from an interruption.
     */
    public function isResuming(): bool
    {
        return $this->resumeRequest instanceof InterruptRequest;
    }

    /**
     * Get the resume request if the node is resuming.
     *
     * This allows middleware to access user decisions when resuming from
     * an interruption.
     *
     * @return InterruptRequest|null The resume request or null if not resuming
     */
    public function getResumeRequest(): ?InterruptRequest
    {
        return $this->resumeRequest;
    }

    /**
     * Emit an event to the workflow-scoped observers.
     *
     * @throws InspectorException
     */
    protected function emit(string $event, mixed $data = null): void
    {
        $workflowId = $this->state->get('__workflowId');
        EventBus::emit($event, $this, $data, $workflowId);
    }
}
