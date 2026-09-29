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

use function hash;
use function is_array;
use function is_callable;
use function serialize;

abstract class Node implements NodeInterface
{
    /** Memo name prefix under which a step records the answer each of its waits received. */
    protected const ANSWER_MEMO = '__answer.';

    /**
     * The inbound resume payload. Null when not resuming; a non-null array
     * (even empty) means this node is resuming and holds the delivered answer.
     */
    protected ?array $payload = null;

    /**
     * True when the answer a wait just returned is a deadline elapsing rather
     * than a delivered event. awaitEvent() surfaces it as a null return.
     */
    protected bool $timedOut = false;

    protected bool $resuming = false;

    protected ?StepMemoizer $memoizer = null;

    protected ?EventDispatcherInterface $dispatcher = null;

    /** The parallel branch the current step runs in, null outside branches. */
    protected ?string $branchId = null;

    /** Whether the inbound answer is its wait's deadline elapsing. */
    protected bool $answerExpired = false;

    /** The wait the inbound answer is for; null gives it to the first wait the node reaches. */
    protected ?string $answeredWait = null;

    /** @var list<string> The memoize() closures running around the next wait, outermost first. */
    protected array $waitScope = [];

    /** How many waits this execution reached in the innermost of those closures, or outside them all. */
    protected int $waitsInScope = 0;

    public function run(Event $event, WorkflowState $state, WorkflowResources $resources): Generator|Event
    {
        // A node that declares two parameters ignores the resources.
        /** @phpstan-ignore method.notFound */
        return $this->__invoke($event, $state, $resources);
    }

    public function setWorkflowContext(NodeContext $context): void
    {
        $this->payload = $context->payload;
        $this->timedOut = $context->timedOut;
        $this->answerExpired = $context->timedOut;
        $this->resuming = $context->resuming;
        $this->memoizer = $context->memoizer;
        $this->dispatcher = $context->dispatcher;
        $this->branchId = $context->branchId;
        $this->answeredWait = $context->answering;
        $this->waitScope = [];
        $this->waitsInScope = 0;
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
        $this->timedOut = $this->answerExpired;

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
        if (!$this->memoizer instanceof StepMemoizer) {
            return $operation();
        }

        // A recorded closure is skipped when the node runs again, waits
        // included, so its waits are numbered apart from the node's others.
        [$scope, $waits] = [$this->waitScope, $this->waitsInScope];
        $this->waitScope[] = $name;
        $this->waitsInScope = 0;
        try {
            return $this->memoizer->memo($name, $operation);
        } finally {
            [$this->waitScope, $this->waitsInScope] = [$scope, $waits];
        }
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
     * The answer is recorded: whenever the node runs again, the same call
     * returns it, so a node may wait more than once as long as it reaches
     * its waits in the same order every time.
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
        $wait = hash('xxh128', serialize([...$this->waitScope, $this->waitsInScope++]));

        if ($this->isResuming() && ($this->answeredWait === null || $this->answeredWait === $wait)) {
            $payload = $this->consumePayload();
            $timedOut = $this->timedOut;
            $this->memoizer?->memo(self::ANSWER_MEMO . $wait, fn (): array => ['payload' => $payload, 'timedOut' => $timedOut]);

            return $payload;
        }

        $answer = $this->memoizer?->get(self::ANSWER_MEMO . $wait);
        if (is_array($answer)) {
            $this->timedOut = $answer['timedOut'];

            return $answer['payload'];
        }

        $shouldInterrupt = is_callable($condition) ? $condition() : $condition;

        if (!$shouldInterrupt) {
            return null;
        }

        // The wait the inbound answer is for would never be reached, and its answer lost.
        if ($this->isResuming()) {
            throw new WorkflowException(
                'A node must reach its waits in the same order every time it runs: '
                . 'this one reached a new wait before the one its answer is for.'
            );
        }

        throw new WorkflowInterrupt($request, $wait);
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

    protected function isResuming(): bool
    {
        return $this->resuming;
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
            $event->branchId = $this->branchId;
        }

        $this->dispatcher->dispatch($event);
    }
}
