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
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use DateTimeImmutable;

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
     * Recall a previously memoized value without running anything, or null.
     *
     * Read-only counterpart to memo(): returns the recorded value when a
     * completed memo exists (typically a prior run's recovery), null otherwise —
     * including when no executor is wired (a node running in isolation has
     * cached nothing).
     *
     * Use this to skip non-replayable work whose terminal value was already
     * persisted, e.g. a StreamingNode that recalled a completed provider response
     * instead of re-opening a non-resumable stream. The write side stays memoize().
     */
    protected function recallMemo(string $name): mixed
    {
        if ($this->memoizer instanceof StepMemoizer) {
            return $this->memoizer->get($name);
        }

        return null;
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
     * Suspend the workflow until an external event named $eventName is delivered.
     *
     * Thin sugar over {@see interrupt()} with a WaitForEventRequest. On first pass
     * the workflow pauses; on resume the scheduler/caller hydrates the request
     * with the matched event payload, and this method returns it so the node can
     * read getPayload(). Finer correlation (e.g. a specific entity id) is done in
     * the node after resume.
     *
     * Returns null when the wait timed out: an optional $expiresAt bounds the
     * wait, and when it elapses the scheduler resumes the workflow with the wait
     * marked expired internally — this method surfaces that as null so the node
     * branches on `if ($result === null)` (the wait produced no event). Node code
     * must NOT compare the clock itself; the null return is the timeout signal.
     */
    protected function awaitEvent(string $eventName, ?DateTimeImmutable $expiresAt = null): ?WaitForEventRequest
    {
        /** @var WaitForEventRequest|null $request */
        $request = $this->interrupt(new WaitForEventRequest($eventName, null, $expiresAt));

        // A timeout resume (deadline elapsed, no event) is surfaced to the node as
        // null. Guard on instanceof so a cross-TYPE resume (e.g. a SleepUntilRequest
        // fed to a wait-for-event) still reaches the declared return type and is
        // rejected with a TypeError at the verb boundary, as before.
        if ($request instanceof WaitForEventRequest && $request->isExpired()) {
            return null;
        }

        return $request;
    }

    /**
     * Suspend the workflow until a clock time. Thin sugar over {@see interrupt()}
     * with a SleepUntilRequest. Whether and when to fire is the scheduler's job.
     */
    protected function sleepUntil(DateTimeImmutable $wakeAt): ?SleepUntilRequest
    {
        /** @var SleepUntilRequest|null $request */
        $request = $this->interrupt(new SleepUntilRequest($wakeAt));
        return $request;
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
