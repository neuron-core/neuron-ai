<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use Throwable;

/**
 * In-process replay engine, the local StepEngineInterface implementation.
 *
 * Persists every executed node as a StepResult. On re-run, previously completed
 * steps are returned from cache without re-executing the node; interrupted steps
 * resume from the stored InterruptRequest; failed steps (marked after an
 * unhandled throwable) retry.
 *
 * Replay is keyed by step id alone: step ids are unique within a run (monotonic
 * traversal index, a branch prefix, a per-name memo suffix), so a completed,
 * non-interrupted, non-failed cached result is always a prior run's work and is
 * safe to skip. There is no generation counter and no scan of stored steps.
 */
class LocalStepEngine implements StepEngineInterface
{
    protected string $runId;

    /**
     * The inbound resume payload staged by prepareExecution(). Null means this run
     * is a fresh start or a crash-recovery replay (no resume); a non-null array
     * (even empty) means a deliberate resume — the interrupted step consumes it.
     */
    protected ?array $pendingPayload = null;

    protected bool $pendingTimedOut = false;

    public function __construct(
        protected PersistenceInterface $persistence,
    ) {
    }

    public function prepareExecution(string $runId, ?array $payload = null, bool $timedOut = false): void
    {
        $this->runId = $runId;
        $this->pendingPayload = $payload;
        $this->pendingTimedOut = $timedOut;
    }

    public function runStep(string $stepId, callable $fn): Generator
    {
        $cached = $this->getStepResult($stepId);

        // Memoized: return a previously completed result without re-executing.
        // Nothing is yielded — streamed events are not replayed. Failed steps
        // are never replayed from cache — they must retry.
        if ($cached instanceof StepResult
            && !$cached->isInterrupted()
            && !$cached->isFailed()
        ) {
            return $cached;
        }

        // Resuming an interrupted step — inject the staged inbound payload.
        if ($cached instanceof StepResult
            && $cached->isInterrupted()
            && $this->pendingPayload !== null
        ) {
            $result = yield from $this->drive($fn($this->pendingPayload, $this->pendingTimedOut));
        } else {
            // Execute the callable, yielding its streamed events through in real time.
            try {
                $result = yield from $this->drive($fn(null, false));
            } catch (Throwable $e) {
                // Record a failed-step marker for crash observability, then rethrow.
                // On recovery the marker makes this step retry (it is never replayed from cache).
                $this->setStepResult($stepId, new StepResult(
                    stepId: $stepId,
                    error: ['message' => $e->getMessage(), 'class' => $e::class],
                ));
                throw $e;
            }
        }

        // Interrupted: the step's terminal event is an InterruptEvent. Persist only an
        // interrupted marker (no throw) so the step resumes on the next run. The
        // InterruptRequest rides the event outbound (→ onSuspend / returned state) but
        // is NOT persisted — it is rebuilt by re-running the node on resume, which keeps
        // developer objects stuffed into a request out of the serializer.
        if ($result->getEvent() instanceof InterruptEvent) {
            $this->setStepResult($stepId, new StepResult(stepId: $stepId, interrupted: true));
            return $result;
        }

        // Persist the completed result.
        $this->setStepResult($stepId, $result);

        return $result;
    }

    /**
     * Normalize the callable's outcome: a Generator streams events through and
     * returns the StepResult; a plain StepResult (e.g. a memo step) has nothing
     * to stream.
     *
     * @return Generator<int, \NeuronAI\Workflow\Events\Event, mixed, StepResult>
     */
    protected function drive(Generator|StepResult $outcome): Generator
    {
        if ($outcome instanceof StepResult) {
            return $outcome;
        }

        return yield from $outcome;
    }

    public function saveStep(string $stepId, StepResult $result): void
    {
        $this->setStepResult($stepId, $result);
    }

    public function deleteSteps(): void
    {
        $this->pendingPayload = null;
        $this->pendingTimedOut = false;

        $this->persistence->delete($this->runId);
    }

    /**
     * Return a successfully-completed step result, or null.
     *
     * Same cache-hit guard as runStep() — interrupted or failed steps are never
     * returned, only completed results from a prior run. Used by the memoizer to
     * recall a durable value WITHOUT running anything, which is what lets a node
     * skip non-replayable work like a provider stream.
     */
    public function getStep(string $stepId): ?StepResult
    {
        $cached = $this->getStepResult($stepId);

        if ($cached instanceof StepResult
            && !$cached->isInterrupted()
            && !$cached->isFailed()
        ) {
            return $cached;
        }

        return null;
    }

    protected function getStepResult(string $stepId): ?StepResult
    {
        return $this->persistence->load($this->runId, $stepId);
    }

    protected function setStepResult(string $stepId, StepResult $result): void
    {
        $this->persistence->save($this->runId, $stepId, $result);
    }
}
