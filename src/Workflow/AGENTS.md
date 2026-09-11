# Workflow Module

Neuron's event-driven orchestration foundation. Agent and RAG are compositions built on it; nothing here knows about AI providers, chat or tools.

## Core model

A workflow routes events through nodes until a `StopEvent`. A node's `__invoke()` input type determines routing; the executor owns traversal, durable replay, suspension and lifecycle transitions, while nodes stay unaware of workers, HTTP requests, queues or cloud platforms.

```php
$state = Workflow::make(state: $state)
    ->addNodes([new NodeA(), new NodeB()])
    ->run();
```

Subclasses build their graph in the `nodes()` / `entryNodes()` hooks, which run fresh at every execution segment, so the graph is always a function of the current configuration. Middleware wraps node execution (`addMiddleware(NodeClass::class, ...)`, subclass-aware, or `addGlobalMiddleware()`): it shapes events before a node acts, while flow control and I/O belong to nodes. Keep platform integration outside middleware and executors; the returned lifecycle outcome is the integration boundary.

## Suspend, inspect, resume

Nodes pause through focused helpers, each producing a portable `InterruptRequest` (`WaitForEventRequest`, `SleepUntilRequest`, `ApprovalRequest`, or your own serializable subclass):

```php
$payload = $this->awaitEvent('order.approved', expiresAt: $deadline);
$this->sleepUntil($wakeAt);
$payload = $this->interrupt(new MyApprovalRequest(...));
```

The executor assigns each interrupt a positive, run-scoped, monotonically increasing ID, persists it, and returns the same ID-bound request through `$state->getInterruptRequests()`. Parallel branches may expose several active interrupts at once; a partial resume runs only the addressed nodes and returns the unaddressed requests from persistence without rerunning theirs.

Three continuation styles share one model:

- **Application code** resumes by signal name: `$workflow->signal('order.approved', $payload)->run()`. The signal is delivered to every currently active `awaitEvent()` with that name, parallel branches included, and is never queued: with no matching wait, `signal()` throws without persisting the payload. Use distinct names or a correlation key when delivery must target one wait.
- **Translated application inputs** use `$workflow->submitInputs($payload, $translator)->run()` (or `events()`). Any `InputTranslatorInterface` can map a transport payload to addressed inputs against the authoritative persisted requests. Submission reads and translates without executing nodes or writing persistence, rejects missing runs and empty translations, and stages the inputs with the observed run ID and execution attempt. An intervening continuation makes them stale. Snapshot lookup is an executor collaboration detail; Workflow exposes no public `inspect()`. Agent inherits this API.
- **Durable platform SDKs** stage addressed, typed inputs: `resume([ResumeInput::event($request, $payload), ResumeInput::timer($sleepRequest), ...], expectedRunId: $runId)->run()`. Nodes never see `ResumeInput`; the executor translates an accepted input into the node's normal return (payload, `null` on expiry, timer wake). One input settles exactly one interruption. Once accepted it is immutable until the step settles (an identical redelivery may recover a failed attempt, a different answer rejects the batch); stale or unknown IDs are reported through `getInputResults()`, and a stale `expectedRunId` rejects the whole batch before traversal.

`run()` and `events()` are argument-free terminals. They consume a staged `resume()`, `signal()`, or `submitInputs()` operation exactly once. With no staged operation they start a run, or automatically recover a persisted `Failed` execution under the same workflow ID. A suspended execution still requires an explicit continuation; a live execution or retained completion refuses an automatic start.

`resume()->run()` is an explicit inputless continuation: it evaluates due timers and expiring waits, recovers interrupted processes according to lease rules, or retrieves a retained completion. `resume($inputs, expectedRunId:, expectedExecutionAttempt:)` stages exact inputs and optional caller-owned identity fences without reading or writing persistence; validation and execution happen at the terminal. `submitInputs()` translates against the persisted requests and delegates staging to `resume()` with the observed identity. Staged operations cannot be combined.


## Platform-owned coordination

The core has no scheduler interface and no suspend/resume/complete callbacks. A platform SDK is an invocation gateway: reconstruct the workflow and its live dependencies through its own factory, configure persistence, serializer, lease and completion retention, stage `resume($inputs, ...)` or `resume(...)` and call `run()`, inspect the returned status, run ID, input dispositions and active interrupts, then reconcile its own timers, subscriptions and jobs. HTTP round trips, queue workers and CLI loops are equivalent callers; platform job IDs, delivery attempts and factory identity never enter Workflow persistence.

The complete `InterruptRequest` stays authoritative in Workflow persistence; a platform stores only the projection it needs to route work (`workflowId`, `runId`, `interruptId`, type, event name, deadline). A delivery job resolves the interrupt-ID set when a signal is first accepted and reuses exactly that set on every retry; it never rematches by name after a lost response. For timers it stores identity plus the earliest deadline and invokes `resume()->run()`. Reconstruction is the factory's job: ignition context may restore small domain identity (the Agent's thread ID), but it is not a dependency container.

## One partition, optimistic ownership

All records for a workflow ID live in one persistence partition:

| key | purpose |
|---|---|
| `__ignition` | immutable start event, run ID, engine-opaque context |
| `__control` | mutable lifecycle authority: run ID, status, execution attempt, lease deadline, next interrupt ID, active interrupts with their accepted inputs |
| `<runId>/__checkpoint` | the suspended run's state |
| `<runId>/__outcome` | the retained terminal state (completion retention only) |
| `<runId>/<stepId>` | durable step result or marker (branch steps hash their parent-fork path) |
| `<runId>/<stepId>::<memo>` | durable memo result |

`PersistenceInterface` offers `get()` plus three atomic intents, `initializeIfAbsent()`, `writeIfUnchanged()` and `deleteIfUnchanged()`; there is no unconditional runtime write or delete. Every mutation is derived from a byte-exact read of `__control` and commits only if control still holds that value:

```text
read control A -> calculate transition -> write only if control is still A
```

A successful claim increments the execution attempt; an older process may finish local work but can no longer commit a step, memo, suspension, failure or cleanup. `__control` never carries workflow state (checkpoint and outcome are separate records written in the same conditional write, keyed under the run ID, so an older process reads its own generation's state or nothing). The backend treats keys and values as opaque strings and understands nothing about runs. `WorkflowRunStore` is the internal boundary that owns reserved keys, serialization, the control snapshot, conditional writes and a segment-local record cache; `StepMemoizer` is a step-bound view of it; `WorkflowExecutor` works with typed `WorkflowControl` and never touches raw bytes.

Backends: `DatabasePersistence` and `EloquentPersistence` are the multi-process candidates and must perform each condition check and its mutation in one transaction; `InMemoryPersistence` is process-local; `FilePersistence` gives restart durability for controlled single-process use only and is deliberately not a worker-farm lock manager. Serializers return raw bytes; transport encoding belongs to persistence.

## Durable steps and memoization

Every completed node step is persisted and skipped on replay; a node that fails before its step commits runs again (failure updates control without writing a failed-step marker). Inside a node, `memoize('name', fn () => ...)` makes an expensive or non-deterministic sub-operation replay-safe; the memo write is fenced by the same control record as step writes. It reuses committed results, it cannot make an uncertain external side effect exactly-once: supply an idempotency key to the external system where that matters. `recallMemo()` is the read-only counterpart for streaming flows.

`restoreState()` reattaches transient dependencies to state recalled from persistence (completed steps, unaddressed interrupts, checkpoints, retained outcomes); it is never called on live results. Serialization and cloning are separate contracts: parallel branches work on clones, so state subclasses with mutable object properties outside the data array must define how they clone.

## Leases, failures, dead generations

Leases are opt-in (`setLeaseTimeout()`; `Agent` holds a ten-minute one by default). The deadline lives inside `__control` and every step commit renews it in the same conditional write, so a heartbeat costs nothing; suspension and caught failure clear it. A process killed with no chance to write leaves the record `running`: without a lease only `resume()->run()` can take it over, with one the next ignition supersedes it once the deadline passes. A lease is a crash-overlap safeguard, not proof the prior process died: choose a timeout longer than the longest silent node operation.

A caught failure commits `failed` and leaves the partition in place. A plain `run()` or `events()` recovers that generation (committed steps and memos reused, only the failed step reruns, same run ID). Automatic recovery fences the observed failed run and attempt before claiming it. A `running` generation whose lease expired is dead in the same sense. Suspended generations, retained completions and live leases refuse a fresh ignition with `RunInFlightException`, whose message names the verb that settles that state (deliver the awaited input, acknowledge the completion, wait for the lease). `abandonRun()` discards a paused, failed or dead run without igniting a new one; a retained completion or a fresh lease refuses. Every sweep is fenced by the control bytes just read, so a recovery worker that claims first keeps the generation.

## Completion

A clean `StopEvent` conditionally deletes the whole partition by default, so completed data does not grow and the workflow ID is free for a new generation. A platform that must survive a lost completion response opts into `retainCompletionUntilAcknowledged()`: the terminal state is committed to `<runId>/__outcome` together with `completed` control, retries replay it without executing nodes, and `acknowledgeCompletion($runId)` purges that exact generation. Core keeps no permanent history; history belongs to the platform or application.

## Executors and streaming

`WorkflowExecutor` owns lifecycle decisions and sequential traversal; `AsyncExecutor` changes only parallel branch execution. Executors type against `WorkflowRuntimeInterface`; application code uses `WorkflowInterface`. A workflow instance runs one segment at a time: starting another `run()` or `events()`, or calling `abandonRun()` / `acknowledgeCompletion()`, while a segment's generator is in flight throws before anything is touched.

Nodes may `yield` live output while a segment streams; `setStreamAdapter()` converts it to protocol lines once, for both pull consumers and an attached `StreamingChannelInterface` (`send()` for native objects, `sendLine()` for lines). The segment's outcome selects the adapter's terminal frames: `end()`, `suspended($requests)` or `error($e)`. Yielded output is ephemeral and never replayed; see `src/Agent/Adapters/AGENTS.md`.
