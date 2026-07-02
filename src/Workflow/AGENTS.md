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
$workflow = Workflow::make($state)
    ->addNodes([new NodeA(), new NodeB()])();

// Stream events
foreach ($workflow->events() as $event) { ... }

// Or get final state
$finalState = $workflow->run();
```

## Interruption (Human-in-the-Loop)

Nodes can pause execution for external input:

```php
// Inside a node
$this->interrupt(new ApprovalRequest(actions: [...]));
```

Workflow throws `WorkflowInterrupt`. Resume later:
```php
try {
    $handler = MyWorkflow::make()->run();
} catch (WorkflowInterrupt $interrupt) {
    $request = $interrupt->getRequest();
    $token = $interrupt->getWorkflowId();

    // ... user approves/rejects ...

    $handler = MyWorkflow::make(resumeToken: $token)->run($resumeRequest);
}
```

**Requires persistence**: `FilePersistence`, `InMemoryPersistence`, `DatabasePersistence`

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
built-in `ChatNode` (inference) and `ToolNode` (per-call tool execution) already use it.

> `checkpoint()` is deprecated and now delegates to `memoize()`. The previous in-memory,
> one-shot behaviour is removed — prefer `memoize()`.

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

## Middleware

Wrap node execution with cross-cutting concerns:

```php
$workflow->middleware(NodeClass::class, new LoggingMiddleware());
$workflow->globalMiddleware(new PerformanceMiddleware());
```

Interface: `before(NodeInterface, Event, WorkflowState)` and `after(NodeInterface, Event, result, WorkflowState)`

## Executors

The executor controls **how** the workflow graph is traversed. `Workflow` delegates to an executor via `resolveExecutor()`.

There are two genuine extension points:

- **`PersistenceInterface`** — where steps are stored (InMemory, File, Database, Eloquent).
- **`WorkflowExecutorInterface`** — the execution model: in-process (`WorkflowExecutor`), async branches (`AsyncExecutor`), or an external platform (a future cloud executor).

```
Workflow
  └─ WorkflowExecutorInterface (execution model)
       ├─ WorkflowExecutor   (in-process; owns traversal + replay + a LocalStepEngine)
       │    └─ AsyncExecutor  (concurrent branches via Amp fibers)
       └─ <CloudExecutor>     (future: platform-driven durability)
  └─ PersistenceInterface (storage, used by the local executor)
       ├─ InMemoryPersistence / FilePersistence / DatabasePersistence / EloquentPersistence
```

`LocalStepEngine` (the replay logic — persist each step, skip completed steps on replay, resume interrupted steps) is an **internal** detail of `WorkflowExecutor`, not something developers construct. The executor builds it from the persistence you give it.

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

// Async parallel branches (requires ext-amp)
$workflow = Workflow::make()
    ->setPersistence(new FilePersistence($dir))
    ->setExecutor(new AsyncExecutor());
$workflow->run();
```

Every node becomes a durable step. Completed steps are skipped on replay; interrupted steps resume from the stored `InterruptRequest`; crashed steps leave a failed marker and retry.
