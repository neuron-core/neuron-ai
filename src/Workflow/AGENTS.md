# Workflow Module

Event-driven orchestration foundation. Agent and RAG are built on top of Workflow.

## Core Concept

Workflows route **Events** through **Nodes** until `StopEvent`:

```
StartEvent → NodeA → EventA → NodeB → EventB → StopEvent
```

Node signature determines routing via reflection:
```php
public function __invoke(SpecificEvent $event, WorkflowState $state): NextEvent
```

## Usage

```php
$workflow = Workflow::make(state: $state)
    ->addNodes([new NodeA(), new NodeB()]);

// Stream events
foreach ($workflow->events() as $event) { ... }

// Or get final state
$finalState = $workflow->run();
```

## Suspend & resume

Nodes can pause execution for external input (a human decision, an external
event, a timer). The pause is surfaced **functionally** — `run()`/`events()`
return normally; no exception reaches the caller.

### The suspend vocabulary

Two axes of extensibility, deliberately separated:

- **`InterruptType` enum (CLOSED)** — `WaitForEvent`, `SleepUntil`. Each case maps
  1:1 to a distinct **scheduler capability** (event-router / timer wheel). Adding a
  type is a framework concern because it requires new scheduler logic — it is NOT
  something app code does.
- **`InterruptRequest` class hierarchy (OPEN)** — `InterruptRequest` is abstract;
  `type()` reports one of the closed enum values. App code subclasses an *existing*
  type to carry a richer payload, but `type()` stays inherited. Approval is one
  payload of `WaitForEvent`, not the center.

Three verbs, all on `Node`. On first pass they throw the internal suspend signal; on
resume they return the inbound **answer** (never the request object back):

```php
// Generic — carry any InterruptRequest subclass OUTBOUND; on resume returns the
// raw payload (the delivered answer), which a custom caller interprets.
$payload = $this->interrupt(new MyApprovalRequest(...));

// Sugar over interrupt() with a WaitForEventRequest. Returns the event payload
// on resume, or null on timeout.
$payload = $this->awaitEvent('order.created', expiresAt: $deadline);
if ($payload === null) { /* timed out — no event arrived */ }

// Sugar over interrupt() with a SleepUntilRequest. Whether/when it fires is the
// scheduler's job (NullScheduler never fires it — it waits for a caller to resume).
$this->sleepUntil($wakeAt);
```

`interrupt()` throws an internal signal the executor catches at the step boundary
and converts into an `InterruptEvent`, which terminates traversal like `StopEvent`.
The returned state is marked interrupted and carries the request **outbound** (for
the caller to render). Resume re-runs only the interrupted step, injecting the
inbound payload; completed branches/nodes are skipped via step replay — no parallel
metadata is carried.

**Outbound vs inbound.** The request is a pure, immutable *outbound* value — the
pause description plus whatever custom context the node wants surfaced (actions,
object instances). The resume **answer** is a separate *inbound* **payload** (a plain
serialization-safe array) plus a `bool $timedOut` flag. They never share an object.

**The request is fire-and-forget — it is not persisted.** Only an `interrupted`
boolean is stored per step; the request itself is rebuilt by re-running the node on
resume (replay-by-rerun). So you can stuff real object instances into a custom
request without them ever being serialized.

```php
$state = MyWorkflow::make()->run();

if ($state->isInterrupted()) {
    $request = $state->getInterruptRequest();   // render to the user (outbound)

    // ... collect the decision / event data ...

    $state = MyWorkflow::make($workflow->getAddress())
        ->resume(['id' => 42]);                 // deliver the inbound payload (no stepId)
}
```

**`resume(null)` is not `resume([])`.** A null payload (the default) *revives*
without delivering anything: a still-suspended run re-emits its request and
re-suspends, a crashed step retries — this is the crash-recovery verb. An
empty array delivers an explicitly empty answer to the waiting step. "No
answer" and "an empty answer" are different facts and never conflated.

**Timeouts are scheduler-driven, never clock comparisons in the node.** When an
`awaitEvent()` deadline elapses, the scheduler resumes the workflow with
`$timedOut = true`; the verb surfaces that as `null`. Node code branches on
`if ($payload === null)` — it must not read the clock itself (clock-skew-fragile).

**Requires persistence**: `FilePersistence`, `InMemoryPersistence`, `DatabasePersistence`.

### Coordination vs state — the scheduler seam

`PersistenceInterface` owns **state** (the partitioned KV store — see *The
unified store* below). `SchedulerInterface` owns **coordination** — deciding
*when* a suspended workflow runs again. They are independent seams routed to
different owners:

```php
$workflow = Workflow::make()
    ->setPersistence(new DatabasePersistence($pdo))   // state — your DB
    ->setSerializer(new IgbinarySerializer())          // record codec (optional; default PhpSerializer)
    ->setScheduler(new MyQueueScheduler());            // coordination — a worker/cloud
```

The default `NullScheduler` is inert: it never wakes anything, preserving the
caller-driven model where a caller re-invokes `resume()` to resume. The executor fires
`onSuspend($address, $runId, $request)` after a suspend — the address is the
resume target, the runId is a **generation stamp** the wakeup must record: a
firing wakeup whose runId no longer matches the address's ignition record is
stale and must be discarded, never delivered. `onResume($address)` fires on a
deliberate resume (a payload was delivered — cancels the satisfied wakeup;
a payload-less revive fires nothing), and `onComplete($address)` on a clean,
fenced terminal `StopEvent` (drop all coordination state for the address). The
deadline (`expiresAt`) lives on the outbound request and the scheduler's timer wheel;
the timeout *fact* arrives inbound via `$timedOut`. Persistence stays a pure
partitioned KV store (no scan, no `findExpired()`) and stores no request — only the
`interrupted` flag.

## The ignition record — runs are self-describing

Every durable run registers its **trigger envelope** on the first segment: an
`Ignition` value object carrying the run's **runId** (its generation stamp),
the start event (the run's cause, and the entry key replay walks from), plus
an engine-opaque **context bag**. This is what makes a suspended run
continuable from a **blank process** that knows only the address — the analog
of Inngest storing and re-delivering the trigger event, or Temporal's
`WorkflowExecutionStarted` history entry with its `memo`.

The executor resolves ignition before anything else. Intent is explicit —
`run()` ignites, `resume()` continues — so the table is flat:

| verb | record at the address? | action |
|---|---|---|
| `run()` | no | fresh ignition: stamp a runId, register the envelope |
| `run()` | yes | **refusal** — throw "run in flight at address" (never adopt) |
| `resume()` | yes | adopt (runId + start event + context; a no-op for a same-instance segment — local state wins) |
| `resume()` | no | throw "no run in flight" (loud — covers never-started AND completed) |

Adoption happens **before** `bootstrap()`, so subclasses can construct
collaborators from the context (the Agent applies its threadId there). The
workflow contributes only vocabulary via two runtime-contract methods —
`makeIgnition(string $runId)` and `adoptIgnition()` — and two protected hooks:
`ignitionContext(): array` (write side) and `applyIgnitionContext(array)`
(read side), both empty by default. The engine never interprets the bag.

The record is the address partition's **generation head**: it lives under the
unprefixed `__ignition` key and names the run currently holding the address.
It is swept with the steps on clean completion: a completed run cannot be
continued. Consequence for plain workflows: a workflow suspended on
`awaitEvent()` can be resumed by a bare `Workflow::make($address)` factory —
including crash-replay via bare `resume()` (no payload) by a recovery worker
that knows nothing about how the run was ignited.

## The unified store — one partitioned KV space

`PersistenceInterface` is a single store of **string values filed under
(partition, key)** — three methods: `put(partition, key, value)` (write-or-
overwrite), `get(partition, key): ?string`, `delete(partition)` (drop a whole
partition). Backends interpret nothing: partition names and values are opaque,
and every backend is exactly one artifact (one array / one directory of
`<partition>.store` files / one `workflow_store` table / one Eloquent model).
Partition names are **arbitrary business strings** (an address may contain
slashes or colons): storing any name safely is the backend's obligation —
`FilePersistence` encodes names into safe filenames and fails loudly on a
dropped write. There are no reserved partitions.

**The engine owns all record semantics and serialization.** The codec is a
workflow-owned seam beside persistence and scheduler —
`setSerializer(Serializer)` (default `PhpSerializer`, alternative
`IgbinarySerializer`) — read by the executor at execute time and applied to
every record. The serializer must be stable across suspend/resume: records
are read back with the codec the workflow is configured with.

What lives where — one partition per **address**, step and memo keys prefixed
by the owning run's **runId** (generation), the ignition record unprefixed as
the generation head:

| partition | key | value | record |
|---|---|---|---|
| `thread-42` | `__ignition` | serialized `Ignition` (carries the runId) | the generation head / trigger envelope |
| `thread-42` | `run_abc123/ChatNode-0` | serialized `StepResult` | a step result |
| `thread-42` | `run_abc123/ChatNode-0::inference` | serialized value | a durable memo |

Clean completion is one **fenced** `delete(address)`: delete and
`onComplete()` fire only while `__ignition` still names the completing run,
so a stale replica finishing late can never destroy a successor's records.
Nothing outside the partition references it — there is no cross-partition
record anywhere, so nothing can orphan.

## The address — runs are addressable

A run's durable records live in the partition named by its **address**: the
business key the workflow declares by overriding **`address(): ?string`**
(`Agent` returns its `threadId`; an order workflow would return
`'order:123'`), or an engine-generated handle (`workflow_<uniqid>`) when it
declares none. The stateless approve/deny endpoint's question — *"given my
business key, which run is pending?"* — needs no index and no scan: the key
**is** the storage location, and one `get(address, '__ignition')` answers it.

**One live run per address, enforced by refusal.** `run()` at an address
holding a live ignition record throws — a pending run is settled by resuming
it (e.g. with decline decisions), never silently replaced. **runId** is the
per-run generation stamp inside the ignition record, re-stamped at every
fresh ignition; it fences writes (step keys are runId-prefixed, so a prior
generation's leftovers at a reused address are invisible to replay) and
stamps scheduler wakeups — it is observability identity, never the
continuation handle.

Addresses must be non-empty and must not start with `__`. The declared
`address()` wins over an explicit `make($address)`; when both are present and
disagree the run is mis-addressed and the engine throws rather than pick one.

### The identity-resolution phase

The executor's first act, before anything reads persistence, is to establish
*where this run lives* and *which generation holds it*:

| instance state | verb | declared/explicit address | action |
|---|---|---|---|
| already resolved (runId set) | — | — | keep it — local state wins, no re-check |
| blank | — | both, disagreeing | throw — mis-addressed run |
| blank | `run()` | none | generate `uniqid('workflow_')` |
| blank | `run()` | present | use it; then refuse if a run is in flight there |
| blank | `resume()` | present | use it; adopt the generation from the ignition record |
| blank | `resume()` | none | throw — the run cannot be addressed |

Consequently `Workflow::getAddress()` and `getRunId()` return `?string`:
identity is **assigned** by this phase, never defaulted at construction, so
"the caller provided one" and "the caller got a default" are no longer
indistinguishable. The state carries both as `__address` and `__runId`.

## Durable memoization (`memoize`)

Every node already executes as a durable step: completed steps are persisted and
**skipped on replay**, so a workflow resumed after a crash or interrupt does not
re-run nodes that already finished.

The remaining danger is **inside** a node. If a node crashes *after* an expensive or
side-effecting operation but *before* it returns, the whole node re-runs on recovery —
re-billing the LLM call, re-sending the email. `memoize()` closes that gap:

```php
// Inside a node
$data = $this->memoize('fetch', fn () => $this->api->fetch($event->query));
```

- On first execution the closure runs and its return value is persisted **mid-node**,
  before the node returns.
- On replay — when the node re-executes because its step crashed before completing —
  the recorded value is returned **without** re-running the closure.

Wrap any non-deterministic or expensive work (LLM calls, HTTP, tool execution) in
`memoize()` so it runs at most once even if the node crashes after it succeeds. The
built-in `ChatNode` (inference — buffered and streamed transport), `ToolNode`
(per-call tool execution), and `StructuredOutputNode` (attempt-indexed
inference) already use it.

### The determinism contract

Replay correctness rests on one rule: **for a given node step, `memoize(name, fn)` must
be a pure function of the node's event and state.** Concretely:

- `__invoke` bodies must be deterministic given their event + state.
- All non-determinism (LLM, HTTP, DB writes, `time()`, randomness) must go **inside**
  `memoize()` — never call it directly in the node body.
- Use a stable `name` per distinct operation; the framework scopes it to the specific
  node-execution automatically.
- For side-effecting operations, `memoize()` gives **at-most-once** execution across
  recovery. Pair it with an idempotency key at the call site for true exactly-once.

Mid-node state mutations (`$state->set(...)`) are not durable — only step boundaries
are. They are discarded if the node crashes and re-applied on replay, which is correct
as long as they are idempotent given the replayed inputs. (Agent chat-history writes
are memoized side effects instead — see `src/Agent/AGENTS.md`.)

### `recallMemo()` — the read-only counterpart

`memoize(name, fn)` fuses compute-or-recall: it always supplies a closure to run on a
miss. That can't express "yield live, then persist," because a closure can't yield into
the node's own generator. `recallMemo(name)` is the read-only half — it returns a
previously recorded value without running anything, or `null`:

```php
$response = $this->recallMemo('inference');
if (!$response instanceof ProviderResponse) {
    foreach ($this->provider->stream(...) as $chunk) { yield $chunk; }   // live consumer
    $response = $stream->getReturn();
    $this->memoize('inference', fn () => $response);                     // record (the write half)
}
```

There is no separate `recordMemo()`: `memoize(fn () => $value)` already persists the
value. `recallMemo` is the only genuinely new capability (the read).

### You cannot memoize a generator

A provider stream is a live, non-resumable cursor — it can't be replayed, and there is
no consumer across a crash to receive chunks. So only the terminal value is durable;
chunks are evanescent. This is why `ChatNode`'s streaming path recalls the
`ProviderResponse` and skips the stream on recovery (pattern above), matching how Temporal/Inngest treat
streams. A crash **mid-stream** re-infers — that is the irreducible cost of a
non-resumable resource. `memoize()` protects the window that matters: the call completed,
so it is never billed twice.

### The two re-run triggers (which helper do I use?)

A node re-runs for one of two reasons, and they are distinguishable:

| Trigger | Signal | Use |
|---|---|---|
| **Interrupt-resume** — this node suspended and is resumed with a payload | `isResuming()` / `getResumePayload()` (the inbound payload is injected) | `consumePayload()` — carries *external input* that didn't exist before |
| **Crash-replay** — the node crashed mid-step and is re-run on recovery | no `InterruptRequest` (memo recall only) | `recallMemo()` / `memoize()` — carries *internal results* already computed |

These are orthogonal by necessity: a resume delivers new information from outside; a
replay recovers old results from inside. One channel cannot represent both. A node may
use both (memoize expensive work, then suspend for approval — on resume the memo is
recalled and the request consumed).

## Middleware

Wrap node execution with cross-cutting concerns:

```php
$workflow->addMiddleware(NodeClass::class, new LoggingMiddleware());
$workflow->addGlobalMiddleware(new PerformanceMiddleware());
```

Interface: `before(NodeInterface, Event, WorkflowState)` and `after(NodeInterface, Event, result, WorkflowState)`

## Executors

The executor controls **how** the workflow graph is traversed. `Workflow` delegates to an executor via `getExecutor()`.

**Two contracts, two audiences.** Applications hold
`WorkflowInterface` (run/resume/events + configuration). Executors type against
`WorkflowRuntimeInterface` — now the **single** engine-facing collaboration contract:
the definition (`getStartEvent`, `getNodeForEvent`, `getEventNodeMap`,
`getMiddlewareForNode`), the run (`getAddress`/`getRunId`/`adoptIdentity`, `address`,
`getState`/`setState`, `restoreEvent`, `getEventDispatcher`), the seams
(`getPersistence`, `getScheduler`, `getSerializer`), and the segment lifecycle (`makeIgnition`,
`adoptIgnition`, `bootstrap`). `Workflow` implements both; anything the engine must call for
correctness belongs on the runtime contract, never on the one users hold. (The
former second engine contract, `StepEngineInterface`, was retired: its replay
logic lives in `WorkflowExecutor` itself.)

There are three genuine extension points:

- **`PersistenceInterface`** — where a run's durable records live (InMemory, File, Database, Eloquent): a three-method partitioned KV of opaque strings — see *The unified store* above. Owns **state**; the record codec is the workflow-owned `Serializer` seam.
- **`SchedulerInterface`** — what wakes a suspended workflow (NullScheduler, a queue/cron worker, a cloud platform). Owns **coordination**. See *Suspend & resume* above.
- **`WorkflowExecutorInterface`** — the execution model: in-process (`WorkflowExecutor`), async branches (`AsyncExecutor`). One verb (`execute`); an executor holds no configuration of its own.

```
Workflow (definition + configuration; owns the seams)
  ├─ PersistenceInterface (state — put/get/delete over partitions of opaque strings)
  │    ├─ InMemoryPersistence / FilePersistence / DatabasePersistence / EloquentPersistence
  ├─ Serializer (record codec — engine-applied; PhpSerializer default, IgbinarySerializer)
  ├─ SchedulerInterface (coordination — wakeups for suspended workflows)
  │    └─ NullScheduler (inert; caller-driven resume) / SelfHostedScheduler / <CloudScheduler>
  └─ WorkflowExecutorInterface (execution model — reads the seams above off the runtime)
       └─ WorkflowExecutor   (the run lifecycle: ignition → bootstrap → replay traversal → terminal)
            └─ AsyncExecutor  (overrides branch execution: concurrent Amp fibers)
```

**Recalled events and transient capability.** A cached step returns its persisted output
event, and events may declare parts of themselves transient (objects that must not
serialize — e.g. the Agent's live tools, dropped in `AIInferenceEvent::__serialize()`).
The symmetric restore seam is `WorkflowRuntimeInterface::restoreEvent(Event $event): Event`:
the engine calls it exactly at its deserialization sites — a cached step's result
event, the adopted ignition start event — never on a live result, so implementations
restore unconditionally (an event arriving there always crossed the serializer).
`Workflow` ships an identity default; subclasses whose events carry live objects
override it (`Agent` re-seeds its tool registry there).

The executor owns the whole run lifecycle in one place: it resolves ignition (register / adopt / refuse), calls the workflow's `bootstrap()`, and traverses nodes as durable steps — persist each step, skip completed steps on replay, resume interrupted steps, retry failed ones. It carries no configuration: constructors are zero-arg, and it reads persistence, scheduler, run id, and the definition off `WorkflowRuntimeInterface` at `execute()` time. Consequence: `setPersistence()`/`setScheduler()` compose freely with any executor — choosing an execution model never affects where state lives.

### Choosing an executor

```php
// Default — in-process, InMemory (no configuration needed)
$workflow = Workflow::make();
$workflow->run();

// Durable — just pick a persistence backend
$workflow = Workflow::make()
    ->setPersistence(new FilePersistence($dir));
$workflow->run();

// Or a database backend
$workflow = Workflow::make()
    ->setPersistence(new DatabasePersistence($pdo));
$workflow->run();

// Async parallel branches (requires ext-amp). Executors carry no storage:
// the persistence you configure serves any executor.
$workflow = Workflow::make()
    ->setPersistence(new FilePersistence($dir))
    ->setExecutor(new AsyncExecutor());
$workflow->run();
```

Every node becomes a durable step. Completed steps are skipped on replay; interrupted steps resume from the stored `InterruptRequest`; crashed steps leave a failed marker and retry.
