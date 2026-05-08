# MEMOIZATION.md — Durable Workflow Execution Plan

## Context & Goals

The Neuron Workflow module currently lacks native durability. State is only persisted when a node calls `$this->interrupt()`, serializing the entire `WorkflowInterrupt` exception (7 fields). If the process crashes between interrupts, all progress is lost.

**This refactoring introduces native memoization into the WorkflowExecutor**, making workflows crash-proof and replayable. The design also serves as the integration point for the Deeplink cloud platform — a developer swaps the `StepEngine` implementation to move durability to the cloud.

### Goals
1. Persist state after every node execution (crash-proof)
2. Skip already-executed nodes on replay (memoization)
3. Unify crash recovery and interrupt resume into a single replay path
4. Provide a `StepEngine` interface that Deeplink implements as a plug-in

### Non-goals
- Modifying the existing `PersistenceInterface` or its implementations (backward compat)
- Changing the `Node.php` interrupt API
- Changing the `Workflow.php` public API
- Implementing `DeeplinkStepEngine` (that lives in the Deeplink SDK, not in Neuron core)

---

## Architecture Overview

### Current Flow
```
Node execution → throws WorkflowInterrupt → executor catches → saves to PersistenceInterface → re-throws
```
No persistence between nodes. Crash = total loss.

### New Flow
```
For each node in traverse():
  1. stepEngine.getResumeRequest(stepId) → check if this step was interrupted and has resume data
  2. stepEngine.runStep(stepId, fn) → memoization + execution combined
     - If cached (completed): return cached Event, skip execution
     - If not cached: execute node → save result → return Event
  3. On WorkflowInterrupt: stepEngine.interruptStep(stepId, request) → store interrupt
```

Crash recovery and interrupt resume use the **same replay path**: start from the beginning, skip memoized nodes, execute the first non-memoized one.

### The StepEngine Abstraction

`StepEngine` abstracts the execution model. It combines memoization, execution, and persistence into a single `runStep()` call:

- **Local execution** (`LocalStepEngine`): check internal cache → if hit, return cached result → if miss, execute callable, save to backend (file/DB/etc.), return result
- **Deeplink execution** (`DeeplinkStepEngine`, in Deeplink SDK): delegates to `$ctx->step->run()` → if memoized, returns cached → if new, executes, records StepRun op, throws `StepPendingException`

The `WorkflowExecutor` is agnostic to which model is in use.

### Deeplink Integration Model

Deeplink's `Step::run()` is a **combined memoization + execution + yield** operation. Each call either returns cached data (replay) or executes the callable, records the op, and throws `StepPendingException` (one step per HTTP round-trip). This maps naturally to the `StepEngine` interface:

| Neuron StepEngine | Deeplink SDK |
|---|---|
| `runStep(id, fn)` | `$ctx->step->run(id, fn)` |
| `interruptStep(id, req)` | `$ctx->step->waitForEvent(id+'.resume', ...)` |
| `getResumeRequest(id)` | `$ctx->step->waitForEvent(id+'.resume', ...)` (memoized) |
| `deleteSteps()` | no-op (platform manages storage) |

Interrupts map to Deeplink's `waitForEvent`: the node pauses, the platform waits for a resume event from the user, then re-invokes the handler with the memoized data.

Developer experience:
```php
// Local durability (out-of-the-box)
$executor = new WorkflowExecutor(stepEngine: new LocalStepEngine());

// Cloud durability (Deeplink plugin — from deeplinq/deeplinq SDK)
$executor = new WorkflowExecutor(stepEngine: new DeeplinkStepEngine($ctx));

// Same workflow, same node code, just swap the step engine
$workflow->setExecutor($executor);
$state = $workflow->run();
```

---

## Strategy

### Phase 1: New Types
Create the `StepEngine` interface and `StepResult` value object. Create the `LocalStepEngine` with in-memory storage.

### Phase 2: Executor Integration
Modify `WorkflowExecutor` to use `StepEngine` for each node execution. The executor wraps node execution in `stepEngine.runStep()`. When `stepEngine` is null, behavior is identical to today (backward compat).

### Phase 3: Interrupt Integration
Handle interrupts through the `StepEngine` interface. When a node throws `WorkflowInterrupt`, the executor calls `stepEngine.interruptStep()`. On replay, `getResumeRequest()` provides the user's decision.

### Phase 4: Production Backends
Add file, database, and Eloquent storage backends for `LocalStepEngine`.

### Phase 5: Cleanup
Deprecate `DeeplinqExecutor`. Update documentation. Update `AsyncExecutor` constructor.

---

## Detailed Implementation

### 1. New File: `src/Workflow/Executor/StepEngine.php`

The core interface. This is the plug point between Neuron and Deeplink.

```php
<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

interface StepEngine
{
    /**
     * Execute a step with memoization.
     *
     * - If the step has a cached (completed) result: return it without executing $fn.
     * - If the step was previously interrupted and has resume data:
     *   the executor checks getResumeRequest() before calling this.
     * - If no cached result: execute $fn, store the result, return it.
     *
     * Implementations may throw to yield control (e.g., StepPendingException
     * in the Deeplink platform). The executor does not catch these — they
     * propagate to the caller.
     *
     * @param string $stepId Unique step identifier (e.g., "NodeClass" or "branchId.NodeClass")
     * @param callable(): Event $fn The node execution callable
     * @return Event The node's output event (memoized or freshly executed)
     */
    public function runStep(string $stepId, callable $fn): mixed;

    /**
     * Record an interrupt at this step position.
     *
     * Called when a node throws WorkflowInterrupt.
     *
     * - Local implementations: store the InterruptRequest for later resume.
     * - Deeplink implementation: record a WaitForEvent op and throw
     *   StepPendingException to signal the platform.
     */
    public function interruptStep(string $stepId, InterruptRequest $request): void;

    /**
     * Check for a pending resume request at this step position.
     *
     * Returns null if the step was not interrupted or no resume data is available.
     * When non-null, the executor passes this as $resumeRequest to the node.
     */
    public function getResumeRequest(string $stepId): ?InterruptRequest;

    /**
     * Clean up step data after a workflow completes successfully.
     */
    public function deleteSteps(): void;
}
```

**Key design decisions:**
- `runStep()` takes a `callable` that returns the Event. This matches Deeplink's `Step::run($id, $fn)` model exactly.
- `interruptStep()` may throw (for Deeplink). The executor does NOT catch this — it propagates up as `StepPendingException`.
- `deleteSteps()` has no `$workflowId` parameter — the `StepEngine` instance is scoped to one workflow run. The workflowId is provided at construction time.

### 2. New File: `src/Workflow/Executor/StepResult.php`

Value object stored by `LocalStepEngine` for each completed or interrupted step.

```php
<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowState;

class StepResult
{
    public function __construct(
        protected string $stepId,
        protected ?Event $event = null,
        protected ?WorkflowState $state = null,
        protected ?InterruptRequest $interrupt = null,
    ) {}

    public function getStepId(): string { return $this->stepId; }
    public function getEvent(): ?Event { return $this->event; }
    public function getState(): ?WorkflowState { return $this->state; }
    public function getInterrupt(): ?InterruptRequest { return $this->interrupt; }

    public function isInterrupted(): bool
    {
        return $this->interrupt !== null;
    }

    public function __serialize(): array { /* serialize all fields using PHP serialize() */ }
    public function __unserialize(array $data): void { /* deserialize all fields */ }
}
```

- **Completed step**: `event` and `state` set, `interrupt` is null
- **Interrupted step**: `interrupt` set, `event` and `state` are null (will be reconstructed by replay)

### 3. New File: `src/Workflow/Executor/LocalStepEngine.php`

The default `StepEngine` implementation for local durability. Uses an internal array for step storage. Production backends (file, database) extend this or provide their own storage.

```php
<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

class LocalStepEngine implements StepEngine
{
    /** @var array<string, StepResult> keyed by stepId */
    protected array $steps = [];

    protected ?InterruptRequest $pendingResume = null;
    protected ?string $interruptedStepId = null;

    public function runStep(string $stepId, callable $fn): mixed
    {
        // Check for memoized (completed) result
        if (isset($this->steps[$stepId]) && !$this->steps[$stepId]->isInterrupted()) {
            $cached = $this->steps[$stepId];
            // State restoration is handled by the executor (which calls setState)
            return $cached->getEvent();
        }

        // No cached result — execute the node
        $result = $fn();

        // Note: state is saved externally by the executor via saveStepResult()
        return $result;
    }

    public function interruptStep(string $stepId, InterruptRequest $request): void
    {
        $this->steps[$stepId] = new StepResult(
            stepId: $stepId,
            interrupt: $request,
        );
        $this->interruptedStepId = $stepId;
        // Does NOT throw — local execution continues, WorkflowInterrupt re-thrown to caller
    }

    public function getResumeRequest(string $stepId): ?InterruptRequest
    {
        if ($this->interruptedStepId !== $stepId) {
            return null;
        }
        if (!isset($this->steps[$stepId]) || !$this->steps[$stepId]->isInterrupted()) {
            return null;
        }
        return $this->pendingResume;
    }

    public function deleteSteps(): void
    {
        $this->steps = [];
        $this->pendingResume = null;
        $this->interruptedStepId = null;
    }

    /**
     * Inject the user's resume request before traversal begins.
     * Called by the executor when resuming from an interrupt.
     */
    public function setResumeRequest(InterruptRequest $request): void
    {
        $this->pendingResume = $request;
    }

    /**
     * Save a completed step result (called by the executor after node execution).
     */
    public function saveStepResult(string $stepId, StepResult $result): void
    {
        $this->steps[$stepId] = $result;
    }

    /**
     * Get a stored step result by step ID.
     * Used by the executor for state restoration after memoized steps.
     */
    public function getStep(string $stepId): ?StepResult
    {
        return $this->steps[$stepId] ?? null;
    }
}
```

**Grey area**: The `LocalStepEngine` needs to be told about the current `WorkflowState` after each node execution, because the callable (`$fn`) executes the node but the state mutation happens inside the node (via `$this->state->set(...)`). The executor needs to extract the state AFTER the node runs and pass it to the engine for storage. This is handled by having the executor call `saveStepResult()` after `runStep()` returns.

### 4. Modify: `src/Workflow/Executor/WorkflowExecutor.php`

This is the core file. Changes:

#### 4a. Constructor

```php
public function __construct(
    ?PersistenceInterface $persistence = new InMemoryPersistence(),
    protected ?StepEngine $stepEngine = null,    // NEW
    NodeRunner $nodeRunner = new DefaultNodeRunner(),
) {}
```

- When `stepEngine` is null: identical to current behavior (backward compat)
- When `stepEngine` is set: uses step engine for memoization, crash recovery, and interrupt storage

#### 4b. `buildStepId()` — New Helper

```php
protected function buildStepId(NodeInterface $node, ?string $branchId): string
{
    return $branchId !== null
        ? $branchId . '.' . $node::class
        : $node::class;
}
```

Matches the format used by the current `DeeplinqExecutor`.

#### 4c. `execute()` — Updated

The main change: when `stepEngine` is set and an interrupt is being resumed, inject the resume request into the step engine before traversal begins.

```php
public function execute(WorkflowInterface $workflow, ?InterruptRequest $interrupt = null): Generator
{
    $workflow->bootstrap();
    $workflowId = $workflow->getWorkflowId();
    EventBus::emit('workflow-start', $workflow, new WorkflowStart($workflow->getEventNodeMap()), $workflowId);
    $workflow->resolveState()->set('__workflowId', $workflowId);

    try {
        // Backward compat: direct-jump resume using PersistenceInterface
        if ($interrupt !== null && $this->stepEngine === null) {
            yield from $this->executeResume($workflow, $interrupt);
        } else {
            // If resuming from interrupt with step engine, inject the resume request
            if ($interrupt !== null && $this->stepEngine instanceof LocalStepEngine) {
                $this->stepEngine->setResumeRequest($interrupt);
            }

            yield from $this->traverse(
                $workflow,
                $workflow->getStartEvent(),
                $workflow->getNodeForEvent($workflow->getStartEvent()::class),
                $this->stepEngine !== null ? null : $interrupt,
            );
        }

        $this->stepEngine?->deleteSteps();
        $this->persistence?->delete($workflowId);
    } catch (WorkflowInterrupt $interrupt) {
        if ($this->stepEngine !== null) {
            $stepId = $this->buildStepId($interrupt->getNode(), $interrupt->getBranchId());
            $this->stepEngine->interruptStep($stepId, $interrupt->getRequest());
            // For Deeplink: interruptStep throws StepPendingException (never reaches here)
            // For Local: interruptStep stores data, we continue to re-throw
        } else {
            $this->persistence?->save($workflowId, $interrupt);
        }
        EventBus::emit('error', $workflow, new AgentError($interrupt, false), $workflowId);
        throw $interrupt;
    } catch (Throwable $exception) {
        EventBus::emit('error', $workflow, new AgentError($exception), $workflowId);
        throw $exception;
    } finally {
        $this->workflowEnd($workflow);
    }

    return $workflow->resolveState();
}
```

**Key behavioral differences with stepEngine vs without:**

| Scenario | stepEngine = null | stepEngine set |
|---|---|---|
| Fresh run | traverse from start | traverse from start, save steps |
| Interrupt resume | `executeResume()` direct-jump using PersistenceInterface | Replay from start, skip memoized, run interrupted node with resumeRequest |
| Crash recovery | Not possible | Replay from start, skip memoized, continue from crash point |
| On interrupt | Save WorkflowInterrupt to PersistenceInterface | Call `stepEngine.interruptStep()`, re-throw |
| On completion | `persistence.delete()` | `stepEngine.deleteSteps()` + `persistence.delete()` |

#### 4d. `traverse()` — Memoization Integration

```php
protected function traverse(
    WorkflowInterface $workflow,
    Event $event,
    NodeInterface $node,
    ?InterruptRequest $resumeRequest = null,
): Generator {
    $workflowId = $workflow->getWorkflowId();

    while (!($event instanceof StopEvent)) {
        $stepId = $this->buildStepId($node, null);

        if ($this->stepEngine !== null) {
            // Durable execution: wrap node in step engine
            $stepResume = $this->stepEngine->getResumeRequest($stepId);

            $event = $this->stepEngine->runStep($stepId, function () use (
                $node, $event, $workflow, $stepResume
            ) {
                $middleware = $workflow->getMiddlewareForNode($node);
                $nodeGen = $this->nodeRunner->run(
                    $node, $event, $workflow->resolveState(), $middleware, null, $stepResume
                );
                foreach ($nodeGen as $_) {}  // consume generator (no streaming for durable)
                return $nodeGen->getReturn();
            });

            // Restore state from cached result (important for memoized steps)
            // AND save state for freshly executed steps
            if ($this->stepEngine instanceof LocalStepEngine) {
                $cached = $this->stepEngine->getStep($stepId);
                if ($cached !== null && $cached->getState() !== null) {
                    $workflow->setState($cached->getState());
                }
                $this->stepEngine->saveStepResult($stepId, new StepResult(
                    stepId: $stepId,
                    event: $event,
                    state: $workflow->resolveState(),
                ));
            }
        } else {
            // Non-durable: direct execution with streaming (current behavior)
            $middleware = $workflow->getMiddlewareForNode($node);
            $nodeGen = $this->nodeRunner->run(
                $node, $event, $workflow->resolveState(), $middleware, null, $resumeRequest
            );
            yield from $nodeGen;
            $event = $nodeGen->getReturn();
            $resumeRequest = null;
        }

        if ($event instanceof ParallelEvent) {
            $branchGen = $this->executeBranches($workflow, $event);
            yield from $branchGen;
            $event = $branchGen->getReturn();
        }

        if ($event instanceof StopEvent) {
            break;
        }

        $node = $workflow->getNodeForEvent($event::class);
    }
}
```

**Grey area — state restoration for memoized steps**: When `runStep()` returns a cached result, the executor needs to restore the `WorkflowState` from the cached `StepResult`. Otherwise the state is stale (it's from the beginning of the workflow, not from after the memoized node). The code above handles this by checking `getStep()` after `runStep()` returns and calling `$workflow->setState()`. This works for BOTH memoized and freshly executed steps:
- Memoized: `getStep()` returns the old cached result with the old state → state restored
- Freshly executed: `getStep()` returns null or the old result → `saveStepResult()` overwrites with new state

**This is a grey area that needs careful thought during implementation.**

#### 4e. `executeBranch()` — Same Pattern

Apply the same memoization to branch node execution. Use `buildStepId($node, $branchId)` for step IDs.

The current `executeBranch()` method catches `WorkflowInterrupt` and wraps it as `BranchInterrupt`. This behavior stays the same. The memoization wraps the individual node execution within the branch loop, not the entire branch.

#### 4f. Keep `executeResume()` for Backward Compat

When `stepEngine` is set, `executeResume()` is no longer needed — the replay path handles interrupt resume automatically. Keep `executeResume()` only for backward compat (when `stepEngine` is null, the `execute()` method branches to use it directly).

### 5. Modify: `src/Workflow/Executor/AsyncExecutor.php`

Update the constructor to pass `stepEngine` to the parent:

```php
public function __construct(
    ?PersistenceInterface $persistence = new InMemoryPersistence(),
    ?StepEngine $stepEngine = null,
    NodeRunner $nodeRunner = new DefaultNodeRunner(),
) {
    parent::__construct($persistence, $stepEngine, $nodeRunner);
}
```

The `executeBranches()` override runs branches as concurrent Amp futures. Each future calls `$this->executeBranch()` which now includes memoization. This should work because each branch has its own step IDs (prefixed with `branchId`).

**Grey area**: Concurrent branches writing to the same `LocalStepEngine` instance. Amp uses single-threaded fibers, so PHP array operations are safe. But file/database backends may need locking.

### 6. Deprecate: `src/Workflow/Executor/DeeplinqExecutor.php`

Add `@deprecated` annotation:

```php
/**
 * @deprecated Use WorkflowExecutor with a StepEngine implementation instead.
 *             For Deeplink integration, use DeeplinkStepEngine from the deeplinq/deeplinq package.
 *             This class will be removed in the next major version.
 */
class DeeplinqExecutor { ... }
```

No code changes. No removal yet.

### 7. New Files: Production Storage Backends

These are storage implementations used by `LocalStepEngine` (or alternative `StepEngine` implementations that persist to external backends).

**Recommended approach**: `LocalStepEngine` accepts a `StepStoreInterface` for pluggable storage:

```php
interface StepStoreInterface
{
    public function save(string $workflowId, string $stepId, StepResult $result): void;
    public function load(string $workflowId, string $stepId): ?StepResult;
    public function delete(string $workflowId): void;
}
```

Then provide implementations following the same patterns as the existing persistence backends:
- `InMemoryStepStore` — array-backed (testing)
- `FileStepStore` — one file per step in a subdirectory per workflowId
- `DatabaseStepStore` — `workflow_steps` table with `(workflow_id, step_id)` composite PK
- `EloquentStepStore` — Eloquent model for the same table

The `LocalStepEngine` would accept an optional `StepStoreInterface` in its constructor. When provided, step data persists across PHP process boundaries (crash recovery). When not provided, steps live only in memory (testing, single-process workflows).

### 8. Test Files

#### New: `tests/Workflow/Executor/DurableExecutorTest.php`

Test cases:
1. **Memoization**: 3-node workflow with `LocalStepEngine` → run → verify all 3 steps saved → run again with same engine → verify nodes NOT re-executed (use a counter property on test nodes)
2. **Crash recovery**: 3-node workflow → run, throw exception after node 2 (simulated crash) → steps 1-2 saved in engine → create new executor with same engine → run → nodes 1-2 skipped, node 3 executes
3. **Interrupt + durability**: workflow interrupts at node 2 → step 1 saved as completed, step 2 saved as interrupted → resume with InterruptRequest → node 1 skipped, node 2 gets resumeRequest, node 3 executes
4. **Step cleanup**: verify `deleteSteps()` called after successful completion
5. **Backward compat**: run existing workflow WITHOUT step engine → verify identical behavior

#### New: `tests/Workflow/Executor/DurableBranchTest.php`

Test cases:
1. **Parallel branch memoization**: 2-branch workflow → run → verify all branch steps saved → run again → all steps memoized
2. **Partial branch crash**: 2-branch workflow → crash after branch 1 completes → re-run → branch 1 memoized, branch 2 runs fresh
3. **Branch interrupt**: interrupt inside branch → resume → completed branch memoized, interrupted branch gets resumeRequest

#### Update: `tests/Workflow/Executor/ExecutorTestHelpers.php`

Add helper:
```php
protected function createDurableExecutor(?StepEngine $stepEngine = null): WorkflowExecutorInterface
{
    return new WorkflowExecutor(
        new InMemoryPersistence(),
        $stepEngine ?? new LocalStepEngine(),
        new DefaultNodeRunner(),
    );
}
```

#### Existing Tests

ALL existing tests must pass WITHOUT modification. This is the backward compat guarantee. If `stepEngine` is null, behavior is identical to today.

---

## Files Summary

### New Files
| File | Purpose |
|------|---------|
| `src/Workflow/Executor/StepEngine.php` | Interface: the plug point for execution models |
| `src/Workflow/Executor/StepResult.php` | Value object: completed or interrupted step data |
| `src/Workflow/Executor/LocalStepEngine.php` | Default implementation: local memoization with in-memory storage |
| `src/Workflow/Executor/StepStoreInterface.php` | (Optional) Storage interface for LocalStepEngine backends |
| `src/Workflow/Executor/InMemoryStepStore.php` | In-memory storage (testing) |
| `src/Workflow/Executor/FileStepStore.php` | File-based storage |
| `src/Workflow/Executor/DatabaseStepStore.php` | PDO-based storage |
| `src/Workflow/Executor/EloquentStepStore.php` | Eloquent-based storage |
| `tests/Workflow/Executor/DurableExecutorTest.php` | Memoization, crash recovery, interrupt durability tests |
| `tests/Workflow/Executor/DurableBranchTest.php` | Parallel branch durability tests |

### Modified Files
| File | Changes |
|------|---------|
| `src/Workflow/Executor/WorkflowExecutor.php` | Constructor (+`stepEngine`), `execute()` (interrupt via step engine, replay path), `traverse()` (memoization), `executeBranch()` (memoization), new `buildStepId()` helper. Keep `executeResume()` for backward compat. |
| `src/Workflow/Executor/AsyncExecutor.php` | Constructor signature update to pass `stepEngine` to parent |
| `src/Workflow/Executor/DeeplinqExecutor.php` | Add `@deprecated` annotation only |

### Unchanged Files
| File | Reason |
|------|--------|
| `PersistenceInterface` + all 4 implementations | Still used when `stepEngine` is null |
| `WorkflowInterrupt` | Still thrown for call stack unwinding |
| `BranchInterrupt` | Still used for branch interrupt wrapping |
| `Node.php` | `interrupt()` / `interruptIf()` unchanged |
| `DefaultNodeRunner.php` | Already passes `$resumeRequest` |
| `NodeRunner.php` | Interface unchanged |
| `Workflow.php` | Entry points unchanged |
| `WorkflowInterface.php` | Unchanged |
| `WorkflowExecutorInterface.php` | Signature unchanged |
| `WorkflowState.php` | Unchanged |
| All Event classes | Unchanged |
| All Interrupt classes (except usage in executor) | Unchanged |
| All Middleware classes | Unchanged |
| All Exporter classes | Unchanged |

---

## Grey Areas Requiring Attention

### 1. State Restoration on Memoized Steps
When a step is memoized and skipped, the `WorkflowState` must be restored from the cached `StepResult`. Without this, subsequent nodes would see stale state. The executor must call `$workflow->setState($cached->getState())` after every memoized step. This needs to work correctly with branch state isolation (cloned state for branches).

### 2. Streaming vs. Durability Trade-off
When `stepEngine` is set, the executor wraps node execution in a callable passed to `runStep()`. This means the node's Generator (streamed events) is consumed inside the callable and NOT re-yielded by the executor's own Generator. This is intentional — memoized steps cannot re-yield events (the previous consumer is gone after crash). But it means `Workflow::events()` won't stream events from individual nodes when durability is enabled. Only `Workflow::run()` (which consumes the generator internally) works naturally with durability. **This trade-off should be documented.**

### 3. Interrupt Resume: `setResumeRequest()` Timing
For the `LocalStepEngine`, the resume request is injected via `setResumeRequest()` before traversal begins. During replay, `getResumeRequest($stepId)` returns it ONLY for the step that was previously interrupted. This means the `LocalStepEngine` needs to track which step was interrupted and only return the resume request for that specific step. After the interrupted node completes, the resume request is consumed and should not be returned again.

### 4. Parallel Branch Concurrency
`AsyncExecutor` runs branches as concurrent Amp futures, all calling `$this->executeBranch()` which accesses the same `LocalStepEngine` instance. Amp uses single-threaded fibers, so PHP array operations are safe. But file or database backends may need locking or connection pooling.

### 5. Step ID Uniqueness
Step IDs are `NodeClass` for main flow and `branchId.NodeClass` for branches. Since `NodeClass` is a fully-qualified class name (e.g., `App\Workflows\ProcessOrderNode`), step IDs are unique within a workflow. But if the SAME node class is used in multiple positions in the graph (e.g., a reusable "validate" node), the step ID would collide. The current architecture doesn't support this (each node class maps to exactly one event type), so this shouldn't be an issue. But it's worth noting.

### 6. Deeplink `Step::has()` Method
The Deeplink `Step` class doesn't currently have a `has(string $id): bool` method. The `DeeplinkStepEngine::getResumeRequest()` needs to check if a `waitForEvent` step is memoized without recording a new op. The Deeplink SDK needs a `has()` method (trivial to add: check if `sha1($id)` exists in `$this->memoized`). This is a Deeplink SDK change, not a Neuron change.

### 7. Deeplink Interrupt <-> WaitForEvent Mapping
The mapping between Neuron's `interrupt()/InterruptRequest` and Deeplink's `waitForEvent()` needs careful design in the Deeplink SDK. The interrupt request data must be serializable as event data that the platform can store and later return as a resume event. This is a Deeplink SDK concern, but the Neuron `StepEngine` interface must accommodate it.

### 8. `LocalStepEngine` State After `runStep()`
The `LocalStepEngine::runStep()` executes the callable and returns the Event. But it doesn't automatically save the state — the executor must call `saveStepResult()` after. This two-step approach (execute + save) means that if the executor crashes between `runStep()` and `saveStepResult()`, the node's result is lost. This is the "at-least-once" execution guarantee. For most workflows, this is acceptable. For non-idempotent nodes, the user should use checkpoints.

---

## Implementation Order

1. **`StepEngine` interface** — define the contract first
2. **`StepResult` value object** — data carrier
3. **`LocalStepEngine`** — in-memory implementation (for testing)
4. **`WorkflowExecutor` changes** — constructor, `execute()`, `traverse()`, `executeBranch()`, `buildStepId()`
5. **`AsyncExecutor` changes** — constructor update
6. **Tests** — memoization, crash recovery, interrupt durability, branches
7. **Run all existing tests** — verify zero regressions
8. **Production storage backends** — `StepStoreInterface`, file/database/eloquent
9. **Deprecate `DeeplinqExecutor`** — add annotation
10. **Update `AGENTS.md`** — document the new architecture

---

## Verification Checklist

- [ ] All existing tests pass without modification (`composer test`)
- [ ] Memoization test: nodes not re-executed on second run
- [ ] Crash recovery test: workflow resumes from last completed step
- [ ] Interrupt durability test: interrupt stored in step engine, resume via replay
- [ ] Parallel branch durability test: completed branches memoized on replay
- [ ] Step cleanup test: steps deleted after successful completion
- [ ] Backward compat test: workflow without step engine works identically to before
- [ ] PHPStan level 5 passes
- [ ] PSR-12 formatting passes (`composer format`)
