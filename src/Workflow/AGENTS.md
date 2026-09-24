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

- `run(ExecutionRequest::resume($payload))` answers the current interruption with one plain array. `resume([])` supplies an empty answer; `resume()` supplies no answer and handles recovery or a due deadline.
- `run(ExecutionRequest::signal('order.approved', $payload))` answers the same current interruption, additionally requiring its event name to match. A mismatch throws without persisting the payload. Signals are neither broadcast nor queued for future or deferred waits.
- `submitInputs($payload, $translator, idempotencyKey: $key)` returns a `PendingExecution` holding the workflow and an immutable `ExecutionRequest` with the answer and observed run/attempt fences; the Workflow supplies its fixed address. The translator is optional: omit it for a native response payload, including `[]`, or supply it to translate an external payload. Chain `->run()` or `->events()` on the result. Native Agent approval/tool-result helpers return the same pending execution type. Submission and creating a stream do not execute nodes or write persistence.


Parallel branches expose interruptions sequentially. The normal executor stops at the first interruption. AsyncExecutor stops starting new nodes but drains nodes already running, including their streams and memo writes, to their terminal result or interruption. Each result is persisted. Concurrent requests wait in arrival order on their branch step records; control holds only deferred step IDs and the current request. Resolving the current step promotes the oldest deferred request, before any new interruption from that step. An accepted reply reaches its waiting node before other branches start new nodes.

The current request blocks later requests, including their deadlines. A deferred deadline keeps its original value; it is evaluated by inputless continuation only after that request becomes current. This design requires no live fibers across segments and does not cancel running external operations. A node may take time to reach its boundary: memoization persists an operation but is not a traversal suspension point.

`run(?ExecutionRequest $request = null)` always returns state; `events()` has the same arguments and always returns a lazy generator. Use `ExecutionRequest::start($event, runId:, idempotencyKey:)`, `::resume($payload, expectedRunId:, expectedExecutionAttempt:, idempotencyKey:)`, or `::signal($name, $payload, expectedRunId:, expectedExecutionAttempt:, idempotencyKey:)`. Import the request from `NeuronAI\Workflow\Executor`.

Requests are independent read-only envelopes, not staged fields on Workflow. Constructing another request cannot overwrite an earlier one. Without a request, the terminals start or automatically recover a failed run. Explicit unkeyed starts select a new generation; keyed/reserved starts refuse existing generations. Inputless resume handles recovery, due deadlines, or retained completion.


With a key, duplicates replay the saved operation outcome or recover the same unfinished operation under lease rules. A saved failure is replayed; deliberate recovery needs a new key. Keyed starts refuse existing generations instead of automatically replacing or recovering them. Receipts are removed during normal run cleanup; there is no post-cleanup deduplication guarantee. See [operation idempotency](../../docs/workflow-idempotency.md).

## Platform-owned coordination

The core has no scheduler interface and no suspend/resume/complete callbacks. A platform SDK is an invocation gateway: reconstruct the workflow and its live dependencies through its own factory, configure persistence, serializer, lease and completion retention, build a resume request and call `run($request)`, inspect the returned status, run ID, execution attempt and current interruption, then reconcile its own timers, subscriptions and jobs. HTTP round trips, queue workers and CLI loops are equivalent callers; platform transport details and factory identity never enter Workflow persistence; an SDK may supply its stable delivery ID as the generic operation idempotency key.

The complete `InterruptRequest` stays authoritative in Workflow persistence; a platform stores only the projection it needs to route work (`workflowId`, `runId`, `interruptId`, type, event name, deadline). A delivery job retains the observed run ID and execution attempt and passes both fences to `ExecutionRequest::resume($payload, ...)`; retries must not rematch by name against a later wait. Accepted input remains immutable until its node settles. Timer jobs use the current request's deadline and invoke a fenced resume request without a payload. Reconstruction is the factory's job: ignition context may restore small domain identity (the Agent's thread ID), but it is not a dependency container.

## Standalone inspection

`WorkflowInspector` reads persisted status and the current interruption without an Agent or Workflow definition:

```php
$inspector = new \NeuronAI\Workflow\WorkflowInspector($persistence);
$snapshot = $inspector->inspect($workflowId);
```

Only `PersistenceInterface` is required. The optional second constructor argument is a `Serializer`, defaulting to `PhpSerializer`; use the same serializer as the executing workflow. Each call reads fresh control and the run's ignition through an independent run store and returns `WorkflowRunSnapshot` or null; the snapshot carries `startEvent`, the run's original input, as its own copy. A run that ends or is replaced between the two reads is read again, and a control record without its ignition raises `WorkflowException`. The inspector retains no identity or run cache and performs no writes. `Workflow::inspect()` uses the same reader through its executor, passing the workflow's configured serializer and bound identity. Null means no current persisted run; it does not distinguish never-started workflows from completed runs already cleaned up. Frontend formatting belongs to the protocol adapters (`AGUIAdapter::hydrate()`), full transcripts to `MessageStoreInterface::loadAll()`.

## One partition, optimistic ownership

All records for a workflow ID live in one persistence partition:

| key | purpose |
|---|---|
| `__ignition` | immutable start event, run ID, engine-opaque context |
| `__operation/<key-hash>` | request fingerprint, ignition binding, and saved operation outcome; removed with the run |
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

Backends: `DatabasePersistence` and `EloquentPersistence` coordinate multiple processes by performing each condition check and its mutation in one transaction; `RedisPersistence` uses one hash per partition and atomic Lua scripts for the same guarantees. `InMemoryPersistence` is process-local; `FilePersistence` gives restart durability for controlled single-process use only and is deliberately not a worker-farm lock manager. Serializers return raw bytes; transport encoding belongs to persistence. Constructing a backend performs no I/O: `FilePersistence` creates its directory on the first write.

`DatabasePersistence` requires a PDO in `PDO::ERRMODE_EXCEPTION` and never changes its attributes; MySQL strict mode is checked at the first write. Inside a transaction the application opened on that PDO, each operation runs in a savepoint and commits or rolls back with the enclosing transaction; a failed operation undoes only its own writes. The PDO's lifetime, including reconnection in long-running workers, is the application's.

`EloquentPersistence` accepts a model class and resolves model queries and the connection at operation time. Creates, updates and deletes use model instances, so model events, scopes, accessors/casts and timestamps participate. A cancelled model mutation throws and rolls back the whole conditional operation. Configure the model with a single primary key, a unique `(partition, key)` constraint and mass assignment for `partition`, `key`, `value`; identifiers hold up to 510 hex characters and values hold base64-encoded bytes. Casts must round-trip those strings. Soft deletes are unsuitable: cleanup must physically remove records. Reads use the write connection, and transactions remain owned by Laravel, including when called inside an application transaction. Model events follow Laravel's normal transaction timing; use after-commit listeners for external effects.

`RedisPersistence` requires optional `ext-redis` and a connected `\Redis` client: `new RedisPersistence($redis, prefix: 'neuron:workflow:')`. The prefix shown is the default; reconnect to the same Redis database and prefix on continuation. The client must be outside a transaction or pipeline. Lua reads and writes preserve opaque bytes independently of client serializer settings. Redis failures raise `PersistenceException`; condition conflicts return `false`. The backend sets no TTL: workflow lifecycle operations own cleanup. Configure Redis persistence and eviction so active and retained runs survive for their required lifetime.

## Durable steps and memoization

Every completed node step is persisted and skipped on replay; a node that fails before its step commits runs again (failure updates control without writing a failed-step marker). Inside a node, `memoize('name', fn () => ...)` makes an expensive or non-deterministic sub-operation replay-safe; the memo write is fenced by the same control record as step writes. It reuses committed results, it cannot make an uncertain external side effect exactly-once: supply an idempotency key to the external system where that matters. `recallMemo()` is the read-only counterpart for streaming flows.

`restoreState()` reattaches transient dependencies to state recalled for an owned execution (completed steps and deferred interruptions); it is never called on live results. Saved outcomes and idle checkpoint polls return persisted data without rebuilding executable dependencies. Serialization and cloning are separate contracts: parallel branches work on clones, so state subclasses with mutable object properties outside the data array must define how they clone.

## Leases, failures, dead generations

Leases are opt-in (`setLeaseTimeout()`; `Agent` holds a ten-minute one by default). The deadline lives inside `__control` and every step commit renews it in the same conditional write, so a heartbeat costs nothing; suspension and caught failure clear it. A process killed with no chance to write leaves the record `running`: without a lease only `run(ExecutionRequest::resume())` can take it over, with one the next ignition supersedes it once the deadline passes. A lease is a crash-overlap safeguard, not proof the prior process died: choose a timeout longer than the longest silent node operation.

A caught failure commits `failed` and leaves the partition in place. A plain `run()` or `events()` recovers that generation (committed steps and memos reused, only the failed step reruns, same run ID). Automatic recovery fences the observed failed run and attempt before claiming it. A `running` generation whose lease expired is dead in the same sense. Suspended generations, retained completions and live leases refuse a fresh ignition with `RunInFlightException`, whose message names the verb that settles that state (deliver the awaited input, acknowledge the completion, wait for the lease). `abandonRun()` discards a paused, failed or dead run without igniting a new one; a retained completion or a fresh lease refuses. Every sweep is fenced by the control bytes just read, so a recovery worker that claims first keeps the generation.

## Completion

A clean `StopEvent` conditionally deletes the whole partition by default, so completed data does not grow and the workflow ID is free for a new generation. A platform that must survive a lost completion response opts into `retainCompletionUntilAcknowledged()`: the terminal state is committed to `<runId>/__outcome` together with `completed` control, retries replay it without executing nodes, and `acknowledgeCompletion($runId)` purges that exact generation. Core keeps no permanent history; history belongs to the platform or application.

## Executors and streaming

`WorkflowExecutor` owns lifecycle decisions and sequential traversal; `AsyncExecutor` changes only parallel branch execution. Admission takes a Workflow definition. Traversal takes `WorkflowRuntimeInterface`, implemented by `WorkflowExecution`; definitions never implement it. Application code uses `WorkflowInterface`. A workflow instance runs one segment at a time: consuming another execution, or calling `abandonRun()` / `acknowledgeCompletion()`, while a segment's generator is in flight throws before anything is touched.

Nodes may `yield` live output while a segment streams; `setStreamAdapter()` converts it once into `ProtocolEvent` value objects. `events()` always returns a lazy generator. Iteration delivers adapted events to a configured channel as well as yielding them. `run()` always consumes execution and returns final state. Without an adapter the pull path yields the native objects and a channel receives only the segment lifecycle. The segment's outcome selects the adapter's terminal frames: `end()`, `interrupt($request)` or `error($e)`. The Workflow knows no transport: SSE framing is applied at the HTTP edge by `SSEEncoder`, and a channel encodes for its own transport. RedisChannel and PusherChannel extend `AbstractChannel`, which owns a sequenced JSON envelope `{streamId, sequence, type, data}`, fragmentation, buffering and delivery failure isolation. The stream ID is unique per segment; fragments share their logical event's sequence and carry `{event, index, total, part}` in `data`. Lifecycle payloads contain only `workflowId`. Consumers must reorder by sequence and reconcile gaps from application history; send order is not a transport delivery guarantee. Transports implement `deliver(string $batch)` and optionally `encode()`, `batch()`, `budget()`, `eventBudget()`, `batchBytes()` and `batchSize()`; the base class enforces measured byte limits. SDK transformations must be covered by a conservative `batchBytes()` upper bound. The first transport failure stops ordinary delivery for the segment; termination separately attempts pending data and the terminal event, then resets channel state. `CallbackChannel` is a direct lifecycle callback adapter and intentionally bypasses this wire contract. Pusher accepts an application-configured `Pusher\Pusher` client (optional `pusher/pusher-php-server` dependency), delegates encryption and signing to it, and defaults to ten events per batch; encrypted payload limits account for ciphertext expansion. Configure SDK timeouts on that client; partial batches wait for further events or termination. Redis Pub/Sub requires a connected client outside a transaction/pipeline. See [channel wire guidance](../../skills/neuron-streaming/references/channels.md) for consumer ordering and transport extension examples. Yielded output is ephemeral and never replayed; see `src/Agent/Adapters/AGENTS.md`.


## Definition and execution ownership

Workflow retains configuration and resource recipes. Each owned segment creates an
`ExecutionContext` (workflow ID, run ID, attempt and detached original input) and a
`WorkflowExecution` containing the state, nodes, middleware and output pipeline.
There is no `prepare:` callback, adopted run ID, live state getter or graph
cache on the definition. Read result identity from returned state and live metadata
from observability events' `execution` property; `source` retains the definition.

Graph hooks receive `WorkflowExecution $execution`. Resource hooks for adapters and
channels receive `ExecutionContext $context`. `setStreamAdapter()` and `setChannel()`
also accept factories returning the resource. Factories never receive a Workflow
to patch. They run only under ownership; saved outcomes and idle polls are passive.

`getWorkflowId()` returns the instance address, or null before binding.
`setWorkflowId()` binds an unbound instance and accepts the same ID again, but
rejects a different ID. The optional constructor ID and the `workflowId()`
declaration hook remain supported. When its `events()` generator starts, Workflow
generates and retains an ID for an unbound start before handing execution to the
executor. A continuation requires an already bound Workflow. Later runs share the
workflow ID and have separate run IDs; executors do not generate or bind the
instance identity.

`ExecutionRequest` carries execution input, run/attempt fences and idempotency,
not the workflow address. `inspect()`, `submitInputs()`, `acknowledgeCompletion()`
and `abandonRun()` use the instance identity and accept no address override.
Inspection and lazy generator creation do not bind an instance; unbound inspection
returns null. Pending submissions retain the bound Workflow and an independent
request capturing the inspected run and attempt, so later submissions cannot
overwrite their input and another worker's continuation cannot silently retarget it.
Generated identities support idempotent retries on the same instance. Queue jobs
must transport the workflow address separately and bind their reconstructed
Workflow before submitting a continuation. Execution contexts, results, snapshots
and persistence retain identity metadata.

Requests serialize input data at creation. `event()` and `payload()` return detached
copies; context `startEvent()` and `domain()` do likewise. Lazy calls capture input
when created and choose configuration when consumed. Never serialize live clients
or closures into input/state. Configured state seeds are copied as serializable data;
`state()` is a factory for fresh state. State subclasses retain responsibility for
cloning mutable custom properties when used as branch states or returned snapshots.

Configured nodes/middleware are prototypes cloned per execution. Their `__clone()`
contract must detach owned mutable fields while retaining explicitly shared clients.
Use node factories (`fn (ExecutionContext $context): NodeInterface => ...`) or
middleware factories (`fn (): WorkflowMiddleware => ...`) for uncloneable services.
Hook-created nodes/middleware are already fresh and are used directly. Factories
that deliberately return shared objects must support sequential reuse. Resource setters
configure the definition without checking whether a segment is active. Identity
setters always enforce the fixed instance address. Once resolved,
resources, graph, middleware, completion policy and dispatcher stay with that segment.
Listener registration preserves earlier dispatcher snapshots. The executor captures
storage, serializer and lease settings before admission. Its local usage gate only
protects overlapping execution and cleanup operations; persisted ownership is separate.
Sharing clients does not imply concurrent safety or protect against direct mutation
of a supplied service. Restoration hooks must use the supplied context and execution
resources rather than reading changing definition settings.


`export($context)` builds a preview graph without admission, output factories or
transport delivery. Without an explicit context it uses a synthetic preview identity
(run ID `preview`, attempt zero). Supply a context for execution-dependent graphs;
resource construction must stay free of business effects.
