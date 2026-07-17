<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Closure;
use Generator;
use Inspector\Exceptions\InspectorException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\EventBus;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\StepMemoizer;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

use function is_callable;

abstract class Node implements NodeInterface
{
    protected WorkflowState $state;
    protected Event $event;
    protected ?InterruptRequest $resumeRequest = null;

    protected ?StepMemoizer $memoizer = null;

    public function run(Event $event, WorkflowState $state): Generator|Event
    {
        /** @phpstan-ignore method.notFound */
        return $this->__invoke($event, $state);
    }

    public function setWorkflowContext(
        WorkflowState $currentState,
        Event $currentEvent,
        ?InterruptRequest $resumeRequest = null,
        ?StepMemoizer $memoizer = null,
    ): void {
        $this->state = $currentState;
        $this->event = $currentEvent;
        $this->resumeRequest = $resumeRequest;
        $this->memoizer = $memoizer;
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

    /**
     * Execute a closure as a durable, memoized sub-operation.
     *
     * On first execution the closure runs and its return value is persisted
     * (mid-node, before the node returns). On replay — when the node re-executes
     * because its step crashed before completing — the recorded value is returned
     * WITHOUT running the closure again.
     *
     * Wrap expensive or non-deterministic work (LLM calls, HTTP, tool execution)
     * in memoize() so it runs at most once even if the node crashes after it
     * succeeds. The closure MUST be a pure function of the node's event and
     * state for the given name.
     *
     * The executor wires a StepMemoizer bound to the current step, which is the
     * default memoization strategy. When a node runs without an executor (e.g. in
     * isolation), the operation simply runs inline with no caching.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    protected function memoize(string $name, Closure $operation): mixed
    {
        if ($this->memoizer instanceof StepMemoizer) {
            return $this->memoizer->memo($name, $operation);
        }

        return $operation();
    }

    /**
     * @deprecated Use memoize() instead. checkpoint() now delegates to memoize(),
     *             persisting the value durably across crashes. The previous
     *             in-memory, one-shot behaviour is removed. Will be removed in
     *             the next major version.
     */
    protected function checkpoint(string $name, Closure $closure): mixed
    {
        return $this->memoize($name, $closure);
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
            throw new WorkflowInterrupt($request);
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
        EventBus::emit(
            $event,
            $this,
            $data,
            $this->state->get('__workflowId'),
            $this->state->get('__branchId')
        );
    }
}
