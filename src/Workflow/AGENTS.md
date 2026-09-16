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

A workflow exposes one current interruption through `$state->getInterruptRequest()`. Requests have positive, run-scoped IDs for correlation; callers do not address response maps by ID.

- `resume($payload)->run()` answers the current interruption with one plain array. `resume([])` supplies an empty answer; `resume()` supplies no answer and handles recovery or a due deadline.
- `signal('order.approved', $payload)->run()` answers the same current interruption, additionally requiring its event name to match. A mismatch throws without persisting the payload. Signals are neither broadcast nor queued for future or deferred waits.
- `submitInputs($payload, $translator)->run()` translates transport data against the single persisted request. `InputTranslatorInterface::translate(array $payload, InterruptRequest $request): array` returns its response payload. Submission reads without executing or writing, and stages the result with the observed run ID and execution attempt. Agent inherits this API for protocol and custom transport payloads. Native Agent tool responses use `submitApprovalDecisions($decisions)` or `submitToolResults($results)`, followed by `run()` or `events()`; application code need not know the internal translators or event names.

Parallel branches expose interruptions sequentially. The normal executor stops at the first interruption. AsyncExecutor stops starting new nodes but drains nodes already running, including their streams and memo writes, to their terminal result or interruption. Each result is persisted. Concurrent requests wait in arrival order on their branch step records; control holds only deferred step IDs and the current request. Resolving the current step promotes the oldest deferred request, before any new interruption from that step. An accepted reply reaches its waiting node before other branches start new nodes.

The current request blocks later requests, including their deadlines. A deferred deadline keeps its original value; it is evaluated by inputless continuation only after that request becomes current. This design requires no live fibers across segments and does not cancel running external operations. A node may take time to reach its boundary: memoization persists an operation but is not a traversal suspension point.

`run()` and `events()` are argument-free terminals. They consume a staged `resume()`, `signal()`, or `submitInputs()` operation exactly once. With no staged operation they start a run, or automatically recover a persisted `Failed` execution under the same workflow ID. A suspended execution still requires an explicit continuation; a live execution or retained completion refuses an automatic start.

`resume()->run()` is an explicit inputless continuation: it evaluates due timers and expiring waits, recovers interrupted processes according to lease rules, or retrieves a retained completion. `resume($payload, expectedRunId:, expectedExecutionAttempt:)` stages one response and optional caller-owned identity fences without reading or writing persistence; validation and execution happen at the terminal. `submitInputs()` translates against the persisted request and delegates staging to `resume()` with the observed identity. Staged operations cannot be combined.


## Platform-owned coordination

The core has no scheduler interface and no suspend/resume/complete callbacks. A platform SDK is an invocation gateway: reconstruct the workflow and its live dependencies through its own factory, configure persistence, serializer, lease and completion retention, stage `resume($payload, ...)` or `resume()` and call `run()`, inspect the returned status, run ID, execution attempt and current interruption, then reconcile its own timers, subscriptions and jobs. HTTP round trips, queue workers and CLI loops are equivalent callers; platform job IDs, delivery attempts and factory identity never enter Workflow persistence.

The complete `InterruptRequest` stays authoritative in Workflow persistence; a platform stores only the projection it needs to route work (`workflowId`, `runId`, `interruptId`, type, event name, deadline). A delivery job retains the observed run ID and execution attempt and passes both fences to `resume($payload, ...)`; retries must not rematch by name against a later wait. Accepted input remains immutable until its node settles. Timer jobs use the current request's deadline and invoke fenced `resume()` without a payload. Reconstruction is the factory's job: ignition context may restore small domain identity (the Agent's thread ID), but it is not a dependency container.

## One partition, optimistic ownership

All records for a workflow ID live in one persistence partition:

| key | purpose |
|---|---|
| `__ignition` | immutable start event, run ID, engine-opaque context |
| `__control` | mutable lifecycle authority: run ID, status, execution attempt, lease deadline, next interrupt ID, current interruption with its accepted input, deferred step IDs |
| `<runId>/__checkpoint` | the suspended run's state |
| `<runId>/__outcome` | the retained terminal state (completion retention only) |
| `<runId>/<stepId>` | durable step result or marker (branch steps hash their parent-fork path) |
| `<runId>/<stepId>::<memo>` | durable memo result |

`PersistenceInterface` offers `get()` plus three atomic intents, `initializeIfAbsent()`, `writeIfUnchanged()` and `deleteIfUnchanged()`; there is no unconditional runtime write or delete. Every mutation is derived from a byte-exact read of `__control` and commits only if control still holds that value:

```text
read control A -> calculate transition -> write only if control is still A
```

A successful claim increments the execution attempt; an older process may finish local work but can no longer commit a step, memo, suspension, failure or cleanup. `__control` never carries workflow state (checkpoint and outcome are separate records written in the same conditional write, keyed under the run ID, so an older process reads its own generation's state or nothing). The backend treats keys and values as opaque strings and understands nothing about runs. `WorkflowRunStore` is the internal boundary that owns reserved keys, serialization, the control snapshot, conditional writes and a segment-local record cache; `StepMemoizer` is a step-bound view of it; `WorkflowExecutor` works with typed `WorkflowControl` and never touches raw bytes.

Backends: `DatabasePersistence` and `EloquentPersistence` coordinate multiple processes by performing each condition check and its mutation in one transaction; `RedisPersistence` uses one hash per partition and atomic Lua scripts for the same guarantees. `InMemoryPersistence` is process-local; `FilePersistence` gives restart durability for controlled single-process use only and is deliberately not a worker-farm lock manager. Serializers return raw bytes; transport encoding belongs to persistence.

`RedisPersistence` requires optional `ext-redis` and a connected `\Redis` client: `new RedisPersistence($redis, prefix: 'neuron:workflow:')`. The prefix shown is the default; reconnect to the same Redis database and prefix on continuation. The client must be outside a transaction or pipeline. Lua reads and writes preserve opaque bytes independently of client serializer settings. Redis failures raise `PersistenceException`; condition conflicts return `false`. The backend sets no TTL: workflow lifecycle operations own cleanup. Configure Redis persistence and eviction so active and retained runs survive for their required lifetime.

## Durable steps and memoization

Every completed node step is persisted and skipped on replay; a node that fails before its step commits runs again (failure updates control without writing a failed-step marker). Inside a node, `memoize('name', fn () => ...)` makes an expensive or non-deterministic sub-operation replay-safe; the memo write is fenced by the same control record as step writes. It reuses committed results, it cannot make an uncertain external side effect exactly-once: supply an idempotency key to the external system where that matters. `recallMemo()` is the read-only counterpart for streaming flows.

`restoreState()` reattaches transient dependencies to state recalled from persistence (completed steps, deferred interruptions, checkpoints, retained outcomes); it is never called on live results. Serialization and cloning are separate contracts: parallel branches work on clones, so state subclasses with mutable object properties outside the data array must define how they clone.

## Leases, failures, dead generations

Leases are opt-in (`setLeaseTimeout()`; `Agent` holds a ten-minute one by default). The deadline lives inside `__control` and every step commit renews it in the same conditional write, so a heartbeat costs nothing; suspension and caught failure clear it. A process killed with no chance to write leaves the record `running`: without a lease only `resume()->run()` can take it over, with one the next ignition supersedes it once the deadline passes. A lease is a crash-overlap safeguard, not proof the prior process died: choose a timeout longer than the longest silent node operation.

A caught failure commits `failed` and leaves the partition in place. A plain `run()` or `events()` recovers that generation (committed steps and memos reused, only the failed step reruns, same run ID). Automatic recovery fences the observed failed run and attempt before claiming it. A `running` generation whose lease expired is dead in the same sense. Suspended generations, retained completions and live leases refuse a fresh ignition with `RunInFlightException`, whose message names the verb that settles that state (deliver the awaited input, acknowledge the completion, wait for the lease). `abandonRun()` discards a paused, failed or dead run without igniting a new one; a retained completion or a fresh lease refuses. Every sweep is fenced by the control bytes just read, so a recovery worker that claims first keeps the generation.

## Completion

A clean `StopEvent` conditionally deletes the whole partition by default, so completed data does not grow and the workflow ID is free for a new generation. A platform that must survive a lost completion response opts into `retainCompletionUntilAcknowledged()`: the terminal state is committed to `<runId>/__outcome` together with `completed` control, retries replay it without executing nodes, and `acknowledgeCompletion($runId)` purges that exact generation. Core keeps no permanent history; history belongs to the platform or application.

## Executors and streaming

`WorkflowExecutor` owns lifecycle decisions and sequential traversal; `AsyncExecutor` changes only parallel branch execution. Executors type against `WorkflowRuntimeInterface`; application code uses `WorkflowInterface`. A workflow instance runs one segment at a time: starting another `run()` or `events()`, or calling `abandonRun()` / `acknowledgeCompletion()`, while a segment's generator is in flight throws before anything is touched.

Nodes may `yield` live output while a segment streams; `setStreamAdapter()` converts it once into `ProtocolEvent` value objects, for both pull consumers and an attached `StreamingChannelInterface`, whose `send(ProtocolEvent)` port receives those same instances. Without an adapter the pull path yields the native objects and a channel receives only the segment lifecycle. The segment's outcome selects the adapter's terminal frames: `end()`, `interrupt($request)` or `error($e)`. The Workflow knows no transport: SSE framing is applied at the HTTP edge by `SSEEncoder`, and a channel encodes for its own transport. Yielded output is ephemeral and never replayed; see `src/Agent/Adapters/AGENTS.md`.
