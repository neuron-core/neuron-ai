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

Three verbs, all on `Node`, all returning the same request subclass back (or null):

```php
// Generic — carry any InterruptRequest subclass
$req = $this->interrupt(new MyApprovalRequest(...));

// Sugar over interrupt() with a WaitForEventRequest. Returns null on timeout.
$event = $this->awaitEvent('order.created', expiresAt: $deadline);
if ($event === null) { /* timed out — no event arrived */ }
$payload = $event->getPayload();

// Sugar over interrupt() with a SleepUntilRequest. Whether/when it fires is the
// scheduler's job (NullScheduler never fires it — it waits for a caller to resume).
$this->sleepUntil($wakeAt);
```

`interrupt()` throws an internal signal the executor catches at the step boundary
and converts into an `InterruptEvent`, which terminates traversal like `StopEvent`.
The returned state is marked interrupted. Resume re-runs only the interrupted step
with the request; completed branches/nodes are skipped via step replay — no
parallel metadata is carried.

```php
$state = MyWorkflow::make()->run();

if ($state->isInterrupted()) {
    $request = $state->getInterruptRequest();   // show to the user / hydrate with an event

    // ... collect decision or deliver event payload into $request ...

    $state = MyWorkflow::make(resumeToken: $workflow->getWorkflowId())->run($request);
}
```

**Timeouts are scheduler-driven, never clock comparisons in the node.** When an
`awaitEvent()` deadline elapses, the scheduler resumes the workflow with the wait
marked expired internally; the verb surfaces that as `null`. Node code branches on
`if ($result === null)` — it must not read the clock itself (clock-skew-fragile).

**Requires persistence**: `FilePersistence`, `InMemoryPersistence`, `DatabasePersistence`.

### Coordination vs state — the scheduler seam

`PersistenceInterface` owns **state** (the KV store of steps). `SchedulerInterface`
owns **coordination** — deciding *when* a suspended workflow runs again. They are
independent seams routed to different owners:

```php
$workflow = Workflow::make()
    ->setPersistence(new DatabasePersistence($pdo))   // state — your DB
    ->setScheduler(new MyQueueScheduler());            // coordination — a worker/cloud
```

The default `NullScheduler` is inert: it never wakes anything, preserving the
caller-driven model where a caller re-invokes `run()` to resume. The executor fires
`onSuspend()` after a suspend is persisted, `onResume()` on a deliberate resume
(cancels the satisfied wakeup — keeps inline resume consistent), and `onComplete()`
on a clean terminal `StopEvent`. Expiration lives in the persisted request terms
**and** the scheduler's timer wheel — persistence stays a pure KV store (no scan,
no `findExpired()`).

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
built-in `ChatNode` (inference), `ToolNode` (per-call tool execution), and
`StreamingNode` (terminal response) already use it.

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

Mid-node state mutations (e.g. `addToChatHistory`, `$state->set(...)`) are not durable —
only step boundaries are. They are discarded if the node crashes and re-applied on replay,
which is correct as long as they are idempotent given the replayed inputs.

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
chunks are evanescent. This is why `StreamingNode` recalls the `ProviderResponse` and
skips the stream on recovery (pattern above), matching how Temporal/Inngest treat
streams. A crash **mid-stream** re-infers — that is the irreducible cost of a
non-resumable resource. `memoize()` protects the window that matters: the call completed,
so it is never billed twice.

### The two re-run triggers (which helper do I use?)

A node re-runs for one of two reasons, and they are distinguishable:

| Trigger | Signal | Use |
|---|---|---|
| **Interrupt-resume** — this node suspended and is woken with a decision/event | `consumeResumeRequest()` / `isResuming()` (an `InterruptRequest` is injected) | `consumeResumeRequest()` — carries *external input* that didn't exist before |
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

The executor controls **how** the workflow graph is traversed. `Workflow` delegates to an executor via `resolveExecutor()`.

There are three genuine extension points:

- **`PersistenceInterface`** — where steps are stored (InMemory, File, Database, Eloquent). Owns **state**.
- **`SchedulerInterface`** — what wakes a suspended workflow (NullScheduler, a queue/cron worker, a cloud platform). Owns **coordination**. See *Suspend & resume* above.
- **`WorkflowExecutorInterface`** — the execution model: in-process (`WorkflowExecutor`), async branches (`AsyncExecutor`), or an external platform (a future cloud executor).

```
Workflow
  └─ WorkflowExecutorInterface (execution model)
       ├─ WorkflowExecutor   (in-process; traversal + a StepEngineInterface collaborator)
       │    └─ AsyncExecutor  (concurrent branches via Amp fibers)
       └─ <CloudExecutor>     (future: platform-driven durability)
  └─ PersistenceInterface (state — storage, owned by the step engine)
       ├─ InMemoryPersistence / FilePersistence / DatabasePersistence / EloquentPersistence
  └─ SchedulerInterface (coordination — wakeups for suspended workflows)
       └─ NullScheduler (inert; caller-driven resume) / SelfHostedScheduler / <CloudScheduler>
```

`StepEngineInterface` is the replay/memoization contract: persist each step, skip completed steps on replay, resume interrupted steps. `LocalStepEngine` is the in-process implementation; it owns persistence and the memoization machinery. `Workflow` constructs it from the persistence you configure and injects it into the executor, so the executor depends on the `StepEngineInterface` abstraction, never on a persistence backend or a concrete engine directly.

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

// Async parallel branches (requires ext-amp). A custom executor owns its own
// persistence (via its LocalStepEngine), so pass it directly — setPersistence()
// is only used when the default executor is built for you.
$workflow = Workflow::make()
    ->setExecutor(new AsyncExecutor(new LocalStepEngine(new FilePersistence($dir))));
$workflow->run();
```

Every node becomes a durable step. Completed steps are skipped on replay; interrupted steps resume from the stored `InterruptRequest`; crashed steps leave a failed marker and retry.
