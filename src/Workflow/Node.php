<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Closure;
use Generator;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\StepMemoizer;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use Psr\EventDispatcher\EventDispatcherInterface;
use DateTimeImmutable;

use function is_callable;

abstract class Node implements NodeInterface
{
    protected WorkflowState $state;
    protected Event $event;

    /**
     * The inbound resume payload. Null when not resuming (fresh run or crash-replay);
     * a non-null array (even empty) means this node is resuming and holds the
     * delivered answer. Set by the executor via setWorkflowContext().
     */
    protected ?array $payload = null;

    /**
     * True when this resume was produced by a wait's deadline elapsing rather than
     * by a delivered event. awaitEvent() surfaces it as a null return.
     */
    protected bool $timedOut = false;

    protected ?StepMemoizer $memoizer = null;

    protected ?EventDispatcherInterface $dispatcher = null;

    public function run(Event $event, WorkflowState $state): Generator|Event
    {
        /** @phpstan-ignore method.notFound */
        return $this->__invoke($event, $state);
    }

    public function setWorkflowContext(NodeContext $context): void
    {
        $this->state = $context->state;
        $this->event = $context->event;
        $this->payload = $context->payload;
        $this->timedOut = $context->timedOut;
        $this->memoizer = $context->memoizer;
        $this->dispatcher = $context->dispatcher;
    }

    /**
     * Consume the inbound resume payload (used internally by nodes).
     * Returns null if not resuming or no payload staged.
     */
    protected function consumePayload(): ?array
    {
        if ($this->payload !== null) {
            $payload = $this->payload;
            // Clear after use so a subsequent interrupt() in the same node re-suspends
            // instead of looping on the same resume.
            $this->payload = null;
            return $payload;
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
     * Suspend the workflow, carrying $request OUTBOUND to the caller/scheduler.
     *
     * On first pass this throws the internal suspend signal (caught at the step
     * boundary). On resume it returns the inbound payload (the raw delivered answer,
     * which a custom caller interprets) — never throwing, so node code after the
     * call runs only on resume.
     *
     * @return array<string, mixed>|null The payload on resume; null only if a condition short-circuited.
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    protected function interrupt(InterruptRequest $request): ?array
    {
        return $this->interruptIf(true, $request);
    }

    /**
     * @return array<string, mixed>|null
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    protected function interruptIf(callable|bool $condition, InterruptRequest $request): ?array
    {
        if ($this->isResuming()) {
            return $this->consumePayload();
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
     * the workflow pauses; on resume this returns the matched event payload (the
     * inbound payload). Finer correlation (e.g. a specific entity id) is done in the
     * node after resume.
     *
     * Returns null when the wait timed out: an optional $expiresAt bounds the
     * wait, and when it elapses the scheduler resumes with $timedOut — this method
     * surfaces that as null so the node branches on `if ($payload === null)`
     * (the wait produced no event). Node code must NOT compare the clock itself;
     * the null return is the timeout signal.
     *
     * @return array<string, mixed>|null The event payload, or null on timeout.
     */
    protected function awaitEvent(string $eventName, ?DateTimeImmutable $expiresAt = null): ?array
    {
        $payload = $this->interrupt(new WaitForEventRequest($eventName, $expiresAt));

        // Reached only on resume. A timeout (deadline elapsed, no event) is surfaced
        // to the node as null.
        if ($this->timedOut) {
            return null;
        }

        return $payload ?? [];
    }

    /**
     * Suspend the workflow until a clock time. Thin sugar over {@see interrupt()}
     * with a SleepUntilRequest. Whether and when to fire is the scheduler's job.
     * A timer resume carries no value, so this returns null on resume.
     */
    protected function sleepUntil(DateTimeImmutable $wakeAt): ?array
    {
        $this->interrupt(new SleepUntilRequest($wakeAt));

        // Reached only on resume — a timer resume delivers no payload.
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
        return $this->payload !== null;
    }

    /**
     * The inbound resume payload, or null when not resuming. Read by middleware
     * (e.g. ToolApproval) to access the delivered answer.
     *
     * @return array<string, mixed>|null
     */
    public function getResumePayload(): ?array
    {
        return $this->payload;
    }

    /**
     * Dispatch an event through the workflow's PSR-14 dispatcher. The event
     * object is the payload; ObservabilityEvent instances are stamped with the
     * emitting node and the current branch. A node running without an
     * executor (e.g. in isolation) has no dispatcher and emits nothing.
     */
    protected function emit(object $event): void
    {
        if (!$this->dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        if ($event instanceof ObservabilityEvent) {
            $event->source = $this;
            $event->branchId = $this->state->get('__branchId');
        }

        $this->dispatcher->dispatch($event);
    }
}
