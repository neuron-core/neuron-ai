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
     * The inbound resume payload. Null when not resuming; a non-null array
     * (even empty) means this node is resuming and holds the delivered answer.
     */
    protected ?array $payload = null;

    /**
     * True when the resume was a deadline elapsing rather than a delivered
     * event. awaitEvent() surfaces it as a null return.
     */
    protected bool $timedOut = false;

    protected bool $resuming = false;

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
        $this->resuming = $context->resuming;
        $this->memoizer = $context->memoizer;
        $this->dispatcher = $context->dispatcher;
    }

    protected function consumePayload(): ?array
    {
        if (!$this->resuming) {
            return null;
        }

        $payload = $this->payload;
        // A ResumeInput satisfies exactly one interruption, including timer
        // and expiry inputs whose payload is null. A later interruption in
        // the same node must create a new interruption.
        $this->payload = null;
        $this->resuming = false;

        return $payload;
    }

    /**
     * Execute a closure as a durable, memoized sub-operation: on crash-replay
     * the recorded value is returned WITHOUT running the closure again. Wrap
     * expensive or non-deterministic work (LLM calls, HTTP, tool execution)
     * in it to reuse committed results. A crash before the memo commits can
     * repeat the operation; external side effects need idempotency. The closure MUST be a pure
     * function of the node's event and state for the given name. Without an
     * executor wired, the operation runs inline with no caching.
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
     * The read-only counterpart to memoize(): the recorded value, or null.
     * Use it to skip non-replayable work whose terminal value was already
     * persisted (e.g. a completed provider response instead of re-opening a
     * non-resumable stream). The write side stays memoize().
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
     * Suspend the workflow, carrying $request OUTBOUND to the caller.
     * On first pass this throws the internal suspend signal; on resume it
     * returns the inbound payload — node code after the call runs only on resume.
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

        return null;
    }

    /**
     * Suspend the workflow until an external event named $eventName is
     * delivered — sugar over {@see interrupt()} with a WaitForEventRequest.
     * On inputless continuation, Workflow resolves an elapsed $expiresAt and
     * this returns null: the null return is the timeout signal — node code must
     * NOT compare the clock itself.
     *
     * @return array<string, mixed>|null The event payload, or null on timeout.
     */
    protected function awaitEvent(string $eventName, ?DateTimeImmutable $expiresAt = null): ?array
    {
        $payload = $this->interrupt(new WaitForEventRequest($eventName, $expiresAt));
        $timedOut = $this->timedOut;
        $this->timedOut = false;

        // Reached only on resume.
        if ($timedOut) {
            return null;
        }

        return $payload ?? [];
    }

    /**
     * Suspend the workflow until a clock time — sugar over {@see interrupt()}
     * with a SleepUntilRequest. Inputless continuation resolves it only after
     * the deadline; an external platform merely schedules that invocation.
     */
    protected function sleepUntil(DateTimeImmutable $wakeAt): ?array
    {
        $this->interrupt(new SleepUntilRequest($wakeAt));

        // Reached only on resume — a timer resume delivers no payload.
        return null;
    }

    public function isResuming(): bool
    {
        return $this->resuming;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResumePayload(): ?array
    {
        return $this->payload;
    }

    /**
     * A node running without an executor (e.g. in isolation) has no
     * dispatcher and emits nothing.
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
