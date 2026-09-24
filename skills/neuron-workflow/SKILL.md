---
name: neuron-workflow
description: Build or modify Neuron AI Workflow graphs, including nodes, events, middleware, persistence, parallel branches, streaming, and human-in-the-loop interruption. Use for tasks specifically involving Neuron's Workflow APIs or custom Neuron agentic orchestration; do not trigger for generic workflow or event-driven design questions.
---

# Neuron AI Workflow

This skill helps you build custom event-driven workflows in Neuron AI. Workflows are the foundation of the entire framework - Agent and RAG are built on top of Workflow.

Setters configure the reusable definition, including while a segment is running. An execution retains its resolved graph, resources, completion policy and event dispatcher. New listener registrations apply to subsequent segments. The executor still rejects overlapping execution and cleanup operations.

## Core Concepts

### Event-Driven Architecture

Workflows operate through events flowing between nodes:

```
StartEvent → Node1 → Event2 → Node2 → Event3 → Node3 → StopEvent
```

Each node:
1. Receives a typed `Event`
2. Processes it
3. Returns a new `Event` (or `StopEvent` to complete)

### The Node Pattern

Nodes extend the `Node` base class:

```php
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class ValidationNode extends Node
{
    // The __invoke signature determines which event this node handles
    public function __invoke(StartEvent $event, WorkflowState $state): ProcessEvent
    {
        $input = $state->get('input');
        $validated = $this->validate($input);
        $state->set('validated', $validated);
        return new ProcessEvent($validated);
    }

    protected function validate(mixed $input): array
    {
        // Validation logic
        return ['valid' => true, 'data' => $input];
    }
}
```

**Key Pattern**: The workflow automatically maps events to nodes based on the first parameter type of `__invoke()`.

### Defining Custom Events

```php
use NeuronAI\Workflow\Events\Event;

class UserValidatedEvent implements Event
{
    public function __construct(
        public readonly string $userId,
        public readonly array $userData
    ) {}
}

class ProcessCompleteEvent implements Event
{
    public function __construct(
        public readonly string $result
    ) {}
}
```

Events should:
- Extend the abstract `Event` base class
- Use readonly properties for immutability
- Contain all data needed by the handling node

## Creating a Workflow

### Basic Workflow

```php
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

$state = new WorkflowState([
    'input' => $userData,
]);

$workflow = Workflow::make(state: $state)
    ->addNodes([
        new ValidationNode(),
        new ProcessingNode(),
        new OutputNode(),
    ]);

$finalState = $workflow->run();
$result = $finalState->get('result');
```

### Using the Static Constructor

```php
class MyWorkflow extends Workflow
{
    /**
     * @return NodeInterface[]
     */
    protected function nodes(\NeuronAI\Workflow\WorkflowExecution $execution): array
    {
        return [
            new ValidationNode(),
            new ProcessingNode(),
        ];
    }
}
```

### Execution API

Import `NeuronAI\Workflow\Executor\ExecutionRequest`. Execution is explicit:

| Call | Meaning |
|---|---|
| `run(ExecutionRequest::start($event, runId: $id, idempotencyKey: $key))` | Fresh input with an optional caller-reserved generation. |
| `run()` | Start or recover a failed run and return state. |
| `run(ExecutionRequest::resume())` | Recover, process a due deadline, or retrieve a retained outcome. |
| `run(ExecutionRequest::resume($answer, expectedRunId: $id, expectedExecutionAttempt: $attempt))` | Deliver a fenced answer. |
| `run(ExecutionRequest::signal($name, $payload))` | Require the current interruption's event name to match. |
| `events($request)` | Always a lazy generator, including when a channel is configured. |
| `abandonRun($runId, $attempt)` | Fenced cleanup, subject to lease/completion protections. |

`run()` always consumes execution; iteration over `events()` also delivers to any
configured channel. Requests are independent values and are never staged on the
workflow. Empty resume payloads are answers; null is inputless continuation.
Signals are neither queued nor broadcast.

`submitInputs()`, Agent `submitApprovalDecisions()` and `submitToolResults()` return
`PendingExecution` objects holding a workflow and its immutable fenced request.
Chain `->run()` or `->events()` on the result. `submitInputs($payload)` accepts a
native interruption response; optionally supply a translator as the second argument
for external payloads. These helpers optionally accept an
`idempotencyKey`; retain the pending execution for retries in the same process.
Use resource hooks or factories receiving `ExecutionContext` for run-dependent resources. They return resources and never mutate a running definition. See `src/Workflow/AGENTS.md` for execution ownership and hook signatures.

## Workflow State

`WorkflowState` carries workflow data between nodes. Parallel branches receive
cloned branch state; return branch results through `StopEvent` and merge them in
the join node instead of relying on concurrent mutation of one state instance:

```php
$state = new WorkflowState();

// Set values
$state->set('user_id', 123);
$state->set('data', ['key' => 'value']);

// Get values
$userId = $state->get('user_id');
$default = $state->get('missing_key', 'default_value');

// Check existence
if ($state->has('data')) {
    // Data exists
}

// Get subset of state
$subset = $state->only(['user_id', 'data']);

// Delete value
$state->delete('data');

// Get all state
$all = $state->all();
```

### Typed Workflow State

`Workflow` is generic in PHPDoc. Bind a custom state once on the subclass;
`run()`, `events()->getReturn()`, and configured `setState()` seeds then retain
that concrete type without forwarding methods or inline assertions:

```php
final class OrderState extends WorkflowState
{
}

/** @extends Workflow<OrderState> */
final class OrderWorkflow extends Workflow
{
    protected function state(): OrderState
    {
        return new OrderState();
    }
}

$state = OrderWorkflow::make()->run(); // inferred as OrderState
```

`Agent` uses the same contract by specializing `Workflow<AgentState>`.

## Persistence and Durability

By default, workflows use `InMemoryPersistence` — results are kept in memory and lost when the process ends. To make workflows **survive crashes and continue after interruptions**, configure a persistent backend.

### How It Works

Each completed node becomes a durable **step** persisted via `PersistenceInterface` — a single partitioned key-value store. A run's records live in the partition named by its workflow ID; the run ID is a generation stamp inside that partition. Completed steps are replayed from cache and never re-executed; the answered interrupted step continues; failed steps retry.

After a caught failure, reconstruct the workflow with the same persistence and
workflow ID and call `run()` or `events()`. A persisted failed execution is recovered
automatically, reusing completed steps and memoized operations.

Use `run(ExecutionRequest::resume())` for explicit inputless continuation, including due timers,
recovery of a process that died without recording failure, and retained outcomes.
Use `run(ExecutionRequest::resume($payload, expectedRunId: $runId, expectedExecutionAttempt: $attempt))` for fenced delivery.
All staging methods are inert; `run()` and `events()` accept an optional operation idempotency key.
Configure context-aware resource factories on the definition before invoking the terminal.

### Persistence Backends

```php
use NeuronAI\Workflow\Persistence\DatabasePersistence;use NeuronAI\Workflow\Persistence\EloquentPersistence;use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\RedisPersistence;

// File system — the directory is created on the first write
$persistence = new FilePersistence('/path/to/storage');

// Database via a PDO in exception mode — requires a workflow_store table
$persistence = new DatabasePersistence($pdo);

// Eloquent model — requires a model with partition, key, value columns
$persistence = new EloquentPersistence(WorkflowStore::class);

// Redis — requires the optional ext-redis PHP extension (PhpRedis)
$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);
$persistence = new RedisPersistence($redis, prefix: 'neuron:workflow:');
```

For multiple workers, use `DatabasePersistence`, `EloquentPersistence`, or
`RedisPersistence`. File storage is for controlled single-process use.

Inside a transaction your application opened on the same connection, the SQL
backends join it: each operation runs in a savepoint and commits or rolls back
with your transaction, so tests wrapped in a transaction work unchanged.

Redis stores each workflow partition in one hash and uses Lua scripts for atomic
conditional writes and deletion. Pass a connected `\Redis` client outside a
transaction or pipeline. The default prefix is `neuron:workflow:`; use the same
Redis database and prefix when reconstructing a workflow. Values remain raw bytes
regardless of client serializer settings. Redis errors raise `PersistenceException`.
The backend sets no TTL; workflow completion and acknowledgement control cleanup.
Configure Redis persistence and eviction to preserve active and retained runs.

### Enabling Persistence

Use the `setPersistence()` shortcut:

```php
$workflow = Workflow::make()
    ->setPersistence(new FilePersistence('/path/to/storage'))
    ->addNodes([...]);

$finalState = $workflow->run();
```

Executors carry no storage of their own. Persistence and serialization are
workflow-owned seams read by the executor at execution time. The record codec
is configurable via `setSerializer()` (default `PhpSerializer`); timers, event
subscriptions, and queue jobs belong to the calling platform, not Workflow core.

### Execution Lease

A caught failure records `failed`, but a process killed with no chance to
write (memory limit, execution timeout, OOM kill) leaves the record `running`,
and the engine cannot tell that apart from a live worker. A lease resolves it:

```php
$workflow->setLeaseTimeout(300);
```

Every step commit renews a deadline inside the control record at no extra
write. A run whose deadline has passed is treated as dead: the next `run()`
supersedes it and `run(ExecutionRequest::resume())` may take it over. Without a lease only `run(ExecutionRequest::resume())`
can take over a `running` record. Pick a value above the longest single node
(a slow provider or tool call). Plain workflows are opt-in; `Agent` holds a
ten-minute lease by default, and `setLeaseTimeout(null)` disables it. A
suspended run holds no lease, so a pause never expires on its own.

### Database Table Schema

When using `DatabasePersistence`, create the single store table. Partition names
and keys are stored hex-encoded, so their 255-byte limit needs 510 characters;
values are base64-encoded.

PostgreSQL / SQLite:

```sql
CREATE TABLE workflow_store (
    "partition" VARCHAR(510) NOT NULL,
    "key"       VARCHAR(510) NOT NULL,
    "value"     TEXT NOT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY ("partition", "key")
);
```

MySQL / MariaDB, in strict SQL mode. ASCII identifiers keep the composite primary
key within InnoDB's 3072-byte limit, and `LONGTEXT` holds states beyond `TEXT`'s
64 KB:

```sql
CREATE TABLE workflow_store (
    `partition` VARCHAR(510) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `key`       VARCHAR(510) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `value`     LONGTEXT CHARACTER SET ascii NOT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`partition`, `key`)
) ENGINE=InnoDB;
```

`EloquentPersistence` takes a model class and uses native model queries and mutations.
For its table, replace the composite primary key shown above with a normal model
primary key (such as `id`) and a unique constraint on `(partition, key)`. Use
510-character identifier columns (ASCII binary collation on MySQL) and a value
column large enough for base64-encoded payloads (`LONGTEXT` on MySQL). Allow mass
assignment of `partition`, `key`, and `value`; configure timestamps on the model
and provide the corresponding columns, or disable timestamps. Casts/accessors must
round-trip the encoded strings. Do not use soft deletes for these records.

Model creation, updates, and deletion emit their normal Eloquent events inside the
conditional transaction. Cancelled mutations roll back the operation. Use
Laravel's after-commit handling for listeners that publish external effects.

### Read status without constructing a workflow

```php
use NeuronAI\Workflow\WorkflowInspector;

$inspector = new WorkflowInspector($persistence);
$snapshot = $inspector->inspect($workflowId);
```

The inspector requires only persistence and defaults to `PhpSerializer`. Pass a
custom serializer as the second argument when the workflow uses one. Each read
returns a fresh `WorkflowRunSnapshot` containing identity, status, execution
attempt, the current interruption and the run's start event (`startEvent`), or
null if there is no persisted control.
Normal completion removes that control; retained completion remains inspectable
until acknowledged. `$workflow->inspect()` remains available for an already
configured workflow.

### Workflow Lifecycle

By default, successful completion conditionally removes the owned workflow
partition. A platform that must replay a lost completion response can call
`retainCompletionUntilAcknowledged()` and later
`acknowledgeCompletion($runId)`. Interrupted workflows retain their active
requests and step records until continued.

## Human-in-the-Loop Patterns

Workflows support interruption for human intervention at any point.

### Interrupting a Node

`InterruptRequest` is the canonical portable description of the pause. The
executor clones it, assigns a positive run-scoped ID, persists it while active,
and exposes that same ID-bound request to callers. Keep request fields
serializable; inject live services into the node instead. On a
continuation, `interrupt()` returns the inbound payload array:

```php
use NeuronAI\Agent\Interrupt\ApprovalRequest;use NeuronAI\Workflow\Interrupt\Action;

class DangerousOperationNode extends Node
{
    public function __invoke(ProcessEvent $event, WorkflowState $state): ResultEvent
    {
        // First pass: suspends here. Continuation pass: returns the delivered payload.
        // ApprovalRequest signature: (string $message, Action[] $actions = [], ?DateTimeImmutable $expiresAt = null)
        $payload = $this->interrupt(new ApprovalRequest(
            'These operations require approval',
            [
                new Action(
                    id: 'delete_files',
                    name: 'Delete Files',
                    description: 'Delete all files in /tmp/uploads'
                ),
                new Action(
                    id: 'send_email',
                    name: 'Send Notification',
                    description: 'Send email to user@example.com'
                ),
            ],
        ));

        // The payload's shape is YOUR contract with the continuing caller.
        foreach ($payload ?? [] as $actionId => $decision) {
            if ($decision === 'approve') {
                $this->executeAction($actionId);
            }
        }

        return new ResultEvent(...);
    }
}
```

### Conditional Interruption

```php
public function __invoke(ProcessEvent $event, WorkflowState $state): ResultEvent
{
    $cost = $state->get('estimated_cost');

    // Only interrupt if cost exceeds threshold; below it, returns null and
    // execution continues straight through.
    $payload = $this->interruptIf(
        $cost > 1000,
        new ApprovalRequest(
            "Operation costs \${$cost}. Approval required.",
            [/* Action[] */],
        )
    );

    return new ResultEvent(...);
}
```

### Persistence for Interruptions

Interruptions are surfaced **functionally** — `run()` returns normally and the
state is marked interrupted (no exception is thrown to the caller):

```php
use NeuronAI\Workflow\Persistence\FilePersistence;

$persistence = new FilePersistence('/tmp/workflows');

$workflow = Workflow::make()
    ->setPersistence($persistence)
    ->addNodes([...]);

$state = $workflow->run();

if ($state->isInterrupted()) {
    // Present the request to the user and retain its engine-assigned ID.
    $request = $state->getInterruptRequest();
    $workflowId = $state->getWorkflowId();

    // ... user approves/rejects ...

    // Application code can deliver the approval signal without managing the
    // request ID. Completed nodes replay; interrupted nodes receive the payload.
    $state = Workflow::make(workflowId: $workflowId)
        ->setPersistence($persistence)
        ->addNodes([...])
        ->run(ExecutionRequest::signal('approval', [
            'delete_files' => 'approve',
            'send_email' => ['reject', 'Do not notify anyone'],
        ]));
}

$result = $state->get('result');
```

Application-controlled event delivery normally uses the event name and workflow
ID; `signal()` checks the current request's event name:

```php
$state = $workflow->run(ExecutionRequest::signal('payment.received', $payload));
```

Delayed, queued, or platform delivery supplies the observed run ID and execution
attempt so a stale response cannot reach a later request:

```php
$state = $workflow->run(ExecutionRequest::resume(
    $payload,
    expectedRunId: $suspendedState->getRunId(),
    expectedExecutionAttempt: $suspendedState->getExecutionAttempt(),
));
```

### Continuing by business key — the workflow ID

A run has two identities: its **workflow ID** — the partition its durable records
live under — and a per-run **runId** (a generation stamp for observability
and fencing, never the continuation handle). Declare the business identity
your application naturally holds (a thread ID, an order ID) by overriding
`workflowId()`. A continuation rebuilt from that business key finds the pending
run without an application-maintained lookup:

```php
class OrderWorkflow extends Workflow
{
    public function __construct(protected string $orderId)
    {
        parent::__construct();
    }

    public function workflowId(): ?string
    {
        return 'order:' . $this->orderId;
    }
}

// Later, a blank process holding only the order ID can inspect the current
// requests without delivering an answer:
$workflow = OrderWorkflow::make(orderId: $orderId)
    ->setPersistence($persistence);
$pending = $workflow->run(ExecutionRequest::resume());
$request = $pending->getInterruptRequest();

// Or deliver a known application signal directly.
$state = $workflow
    ->run(ExecutionRequest::signal('approval', ['delete_files' => 'approve']));
```

Rules: **one live run per workflow ID** — a plain `run()` starts or recovers a
failed run and throws `RunInFlightException` when a live one holds the ID: a
suspended run, a retained completion, or a running attempt whose lease has not
expired. The exception carries `runId`, `status`, `executionAttempt`,
`leaseExpiresAt`, and the current `interrupt`, and its message names the verb
that settles the state. A failed generation is recovered automatically.
A running generation whose lease expired is swept on a new start. Settle a pending run with
`run(ExecutionRequest::signal(...))` or `run(ExecutionRequest::resume($payload))`, or discard it with `abandonRun()`.
Completed records are swept by default,
so a later explicit continuation such as `run(ExecutionRequest::resume())` throws "No run in flight";
a no-input `run()` may start a new generation. A continuation with no workflow
ID at all throws. A declared `workflowId()` wins over an explicit
`make($workflowId)`; a disagreement throws (misidentified run). Plain workflows
that declare no key get a generated workflow ID (read it from the returned state after the
first segment) and are otherwise unaffected. The `Agent` uses exactly this
mechanism, with the Agent thread ID used as the workflow ID.

## Interruption Vocabulary (beyond approval)

Approval is the most common reason to suspend, but it is just one payload. The
interruption model has two axes:

- **`InterruptType` enum (closed)** — `WaitForEvent`, `SleepUntil`. Each maps to a
  coordination capability such as an event subscription or timer. Adding a type
  is a framework concern.
- **`InterruptRequest` class hierarchy (open)** — `InterruptRequest` is abstract.
  Subclass an existing type to add portable coordination or presentation data;
  `type()` remains inherited.

On the first pass, `interrupt()`, `awaitEvent()`, and `sleepUntil()` pause the
node internally. Once that interruption is answered, execution re-enters the
node and the verb returns the inbound payload or timeout result—never the request
object.

### Wait for an external event — `awaitEvent()`

```php
class OrderNode extends Node
{
    public function __invoke(ProcessEvent $event, WorkflowState $state): ResultEvent
    {
        // Suspend until an event named 'payment.received' is delivered.
        // $deadline bounds the wait; if it elapses with no event, returns null.
        $wait = $this->awaitEvent('payment.received', expiresAt: $state->get('deadline'));

        if ($wait === null) {
            return new OrderExpiredEvent();           // timed out — no event arrived
        }

        return new ResultEvent($wait);                // delivered payload array
    }
}
```

A signal delivers the matched event data as the inbound payload; the node's
`awaitEvent()` call returns it:

```php
$state = Workflow::make(workflowId: $workflowId)
    ->setPersistence($persistence)
    ->addNodes([...])
    ->run(ExecutionRequest::signal('payment.received', $paymentPayload));
```

When the deadline elapses, a timer worker invokes `run(ExecutionRequest::resume())`. Workflow validates
the clock, resolves every currently due wait, and `awaitEvent()` returns `null`.
Branch on the node result rather than comparing clocks inside the node;
expiry is determined internally by the executor.

### Sleep until a clock time — `sleepUntil()`

```php
// Suspend until $wakeAt. Workflow core records the request but does not run a timer.
$this->sleepUntil($wakeAt);
```

When an external timer fires, reconstruct the workflow and call `run(ExecutionRequest::resume())`;
Workflow checks whether the wake time is actually due. Timer jobs do not
construct inputs or need interruption IDs.

### Carrying a custom payload

Subclass an existing type to add typed portable fields. `type()` and input
validation are inherited; expose custom transport metadata explicitly:

```php
class QuotaRefreshRequest extends WaitForEventRequest
{
    public function __construct(public readonly string $customerId)
    {
        parent::__construct('quota.refreshed.' . $customerId);
    }

    protected function metadata(): array
    {
        return ['customerId' => $this->customerId];
    }
}

// In a node:
$payload = $this->interrupt(new QuotaRefreshRequest($customerId));
```

### Coordination stays outside Workflow core

Workflow returns one current request. The invoking application or platform
reconciles its subscription or timer and returns one response when ready.
There is no scheduler interface or scheduler state inside the workflow:

```php
$state = $workflow->run();

if ($request = $state->getInterruptRequest()) {
    $platform->reconcile($state->getWorkflowId(), $state->getRunId(), $request);
}
```

## Durable Memoization (`memoize`)

Each node executes as a durable step, so completed nodes are skipped on replay.
`memoize()` closes the remaining gap: expensive or side-effecting work **inside**
a node is persisted mid-node and not re-run when the node re-executes after a
crash or interruption.

```php
class DataProcessingNode extends Node
{
    public function __invoke(ProcessEvent $event, WorkflowState $state): ResultEvent
    {
        // Persisted before the node returns; on continuation the closure is NOT re-run.
        $data = $this->memoize('fetch_data', function () {
            return $this->fetchExpensiveData();
        });

        // Might interrupt here. On continuation this node re-runs, but the memoized
        // $data above is returned without re-calling fetchExpensiveData().
        $payload = $this->interruptIf(
            $needsApproval,
            new ApprovalRequest('Approve data processing', [/* Action[] */])
        );

        // null = never interrupted (condition false); otherwise the payload
        // delivered by run()/events() — its shape is your contract with the caller.
        if ($payload === null || ($payload['approved'] ?? false)) {
            return new ResultEvent($data);
        }

        return new AnotherEvent();
    }
}
```

> The closure must be a pure function of the node's event and state for the given
> name. Put all non-determinism (LLM, HTTP, DB writes, `time()`, randomness)
> **inside** `memoize()`.

### `recallMemo()` — read a memoized value without running anything

`memoize(name, fn)` always supplies a closure to run on a miss, so it can't express
"yield chunks live, then persist the final value" — a closure can't `yield` into the
node's own generator. `recallMemo(name)` is the read-only counterpart: it returns a
prior-run cached value or `null`. There is no separate `recordMemo()`; `memoize(fn () => $v)`
already persists.

### You can't memoize a generator (streaming)

A provider stream is a live, non-resumable cursor — it can't be replayed, and there is
no consumer across a crash to receive chunks. So only the **terminal** value is durable;
chunks are evanescent. The built-in `StreamingNode` uses exactly this pattern: recall the
`ProviderResponse` and skip the stream on recovery, recording the response once it
completes.

```php
public function __invoke(ProcessEvent $event, WorkflowState $state): \Generator
{
    $response = $this->recallMemo('inference');
    if (!$response instanceof ProviderResponse) {
        foreach ($this->provider->stream(...) as $chunk) {
            yield $chunk;                              // live consumer gets real streaming
        }
        $response = $stream->getReturn();
        $this->memoize('inference', fn () => $response); // record once, at-most-once
    }

    // ...use $response...
    return new ResultEvent($response);
}
```

A crash **mid-stream** re-infers — that is the irreducible cost of a non-resumable
resource. `memoize()` protects the window that matters: once the call completed, it is
never billed twice. This matches how Temporal and Inngest treat streams.

## Middleware System

Middleware wraps node execution for cross-cutting concerns.

### Creating Custom Middleware

```php
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

class LoggingMiddleware implements WorkflowMiddleware
{
    public function __construct(private \Psr\Log\LoggerInterface $logger) {}

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        $this->logger->info("Executing: " . $node::class);
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        $this->logger->info("Completed: " . $node::class);
    }
}
```

### Registering Middleware

```php
// Node-specific middleware
$workflow->addMiddleware(ProcessingNode::class, new LoggingMiddleware($logger));

// Multiple middleware on one node
$workflow->addMiddleware(ProcessingNode::class, [
    new ValidationMiddleware(),
    new LoggingMiddleware(),
]);

// Global middleware (runs on all nodes)
$workflow->addGlobalMiddleware(new PerformanceMiddleware());
```

### Execution Order

```
before() calls → Node execution → after() calls
```

All `before()` methods execute in registration order, then the node, then all `after()` methods.

## Streaming Support

Nodes can return a `Generator` to yield live output while they run. Yielded values are
stream output only: the generator must still **return** a normal workflow `Event` to
route to the next node.

```php
use NeuronAI\Agent\Adapters\Events\ActivityStreamEvent;

class ProcessingNode extends Node
{
    public function __invoke(ProcessEvent $event, WorkflowState $state): \Generator
    {
        yield new ActivityStreamEvent(id: $event->jobId, type: 'indexing', data: ['processed' => 10, 'total' => 100]);

        $result = $this->memoize('indexing', fn () => $this->longRunningOperation());

        return new ResultEvent($result);
    }
}
```

Yielded output is live and ephemeral. Durable replay restores the node's returned
event; it never replays earlier progress. Never make workflow correctness depend on a
client receiving these events.

### Consuming Streams

```php
$generator = $workflow->events();

foreach ($generator as $item) {
    if ($item instanceof ActivityStreamEvent) {
        echo $item->type . PHP_EOL;
    }
}

$finalState = $generator->getReturn();
```

The portable event vocabulary, protocol adapters (`setStreamAdapter()`), and push
delivery through channels (`setChannel()`) are covered by the **neuron-streaming** skill.

## Workflow Export

Export workflows to diagram formats for visualization.

```php
use NeuronAI\Workflow\Exporter\MermaidExporter;

$workflow->setExporter(new MermaidExporter());
$diagram = $workflow->export();

// Produces Mermaid flowchart showing event→node flow
```

## CLI Generation

```bash
vendor/bin/neuron make:workflow DataProcessingWorkflow
```

## Best Practices

### Node Design
- Keep nodes focused and single-purpose
- Use typed events for input/output
- Make nodes testable in isolation
- Use `memoize()` for expensive operations before interruption points

### State Management
- Store shared data in WorkflowState, not node properties
- Use descriptive keys for state data
- Clean up state that's no longer needed

### Middleware
- Use middleware for cross-cutting concerns
- Order matters - register in logical sequence
- Prefer node-specific middleware over global

### Interruptions
- Use durable persistence when an interruption must survive process loss or continue elsewhere
- Provide clear, actionable descriptions in InterruptRequest
- Keep request coordination data and metadata serializable; keep services on nodes
- Use `memoize()` to avoid re-running expensive operations across an interruption
- Use `abandonRun()` to discard a run whose awaited event or timer will never come, so the workflow ID is free again

## Common Patterns

### Sequential Processing
```php
class SequentialWorkflow extends Workflow
{
    /**
     * @return NodeInterface[]
     */
    protected function nodes(\NeuronAI\Workflow\WorkflowExecution $execution): array
    {
        return [
            new ValidationNode(),
            new ProcessingNode(),
            new OutputNode(),
        ];
    }
}
```

### Branching Logic
```php
class RouterNode extends Node
{
    public function __invoke(ProcessEvent $event, WorkflowState $state): Event
    {
        if ($state->get('priority') === 'high') {
            return new HighPriorityEvent($event->data);
        }
        return new LowPriorityEvent($event->data);
    }
}
```

### Loop Pattern
```php
class LoopNode extends Node
{
    public function __invoke(ProcessEvent $event, WorkflowState $state): Event
    {
        $items = $state->get('items');
        $current = $state->get('current_index', 0);

        if ($current < count($items)) {
            $state->set('current_item', $items[$current]);
            $state->set('current_index', $current + 1);
            return new ProcessItemEvent($items[$current]);
        }

        return new StopEvent();
    }
}
```

## Parallel Execution

When a node needs to run multiple sub-tasks concurrently (e.g. extracting structured data from an image while also generating a description), use `ParallelEvent` to fork execution into parallel branches.

### How It Works

```
ForkNode → ParallelEvent([branch1 => EventA, branch2 => EventB])
              ├─ BranchA → NodeA → StopEvent(resultA)
              └─ BranchB → NodeB → StopEvent(resultB)
           → JoinNode (reads results from ParallelEvent) → StopEvent
```

1. A **fork node** returns a `ParallelEvent` subclass with branch-starting events.
2. The executor runs each branch independently until `StopEvent`.
3. Each branch's `StopEvent::getResult()` is collected into the `ParallelEvent`.
4. A **join node** (whose `__invoke()` accepts the `ParallelEvent` subclass) reads the results.

### Step 1 — Define a ParallelEvent Subclass

```php
use NeuronAI\Workflow\Events\ParallelEvent;

class ImageAnalysisParallelEvent extends ParallelEvent {}
```

### Step 2 — Create the Branch Events

```php
use NeuronAI\Workflow\Events\Event;

class ExtractStructuredDataEvent implements Event
{
    public function __construct(public readonly string $imageUrl) {}
}

class GenerateDescriptionEvent implements Event
{
    public function __construct(public readonly string $imageUrl) {}
}
```

### Step 3 — Create the Fork Node

```php
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class AnalyzeImageForkNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): ImageAnalysisParallelEvent
    {
        $imageUrl = $state->get('image_url');

        return new ImageAnalysisParallelEvent([
            'structured' => new ExtractStructuredDataEvent($imageUrl),
            'description' => new GenerateDescriptionEvent($imageUrl),
        ]);
    }
}
```

Branch IDs come from non-empty string array keys (`'structured'`,
`'description'`). Sequential arrays are rejected; every branch must be named.

### Step 4 — Create Branch Nodes (Each Ends with StopEvent)

```php
use NeuronAI\Agent;use NeuronAI\HttpClient\Amp\AmpHttpClient;use NeuronAI\Providers\OpenAI\OpenAI;use NeuronAI\Workflow\Events\StopEvent;use NeuronAI\Workflow\Node;use NeuronAI\Workflow\WorkflowState;

class ExtractStructuredDataNode extends Node
{
    public function __invoke(ExtractStructuredDataEvent $event, WorkflowState $state): StopEvent
    {
        $agent = Agent::make()
            ->setProvider(
                (new OpenAI(getenv('OPENAI_API_KEY'), 'gpt-4o'))
                    ->setHttpClient(new AmpHttpClient())
            )
            ->addTool([/* ... */])
            ->setInstructions('Extract structured data from the image.');

        $result = $agent->structured(/* your structured output class */);

        return new StopEvent(result: $result);
    }
}

class GenerateDescriptionNode extends Node
{
    public function __invoke(GenerateDescriptionEvent $event, WorkflowState $state): StopEvent
    {
        $agent = Agent::make()
            ->setProvider(
                (new OpenAI(getenv('OPENAI_API_KEY'), 'gpt-4o'))
                    ->setHttpClient(new AmpHttpClient())
            )
            ->setInstructions('Describe the image in detail.');

        $description = $agent->chat($event->imageUrl);

        return new StopEvent(result: $description);
    }
}
```

### Step 5 — Create the Join Node

```php
class MergeAnalysisNode extends Node
{
    public function __invoke(ImageAnalysisParallelEvent $event, WorkflowState $state): StopEvent
    {
        $structuredData = $event->getResult('structured');
        $description = $event->getResult('description');

        $state->set('analysis', [
            'data' => $structuredData,
            'description' => $description,
        ]);

        return new StopEvent();
    }
}
```

### Step 6 — Wire Up the Workflow

```php
$workflow = Workflow::make(
        state: new WorkflowState(['image_url' => 'https://example.com/photo.jpg'])
    )
    ->addNodes([
        new AnalyzeImageForkNode(),
        new ExtractStructuredDataNode(),
        new GenerateDescriptionNode(),
        new MergeAnalysisNode(),
    ]);

$state = $workflow->run();
```

### Sequential vs Concurrent Execution

By default, `WorkflowExecutor` runs branches **sequentially** (one after another). For true concurrency, use `AsyncExecutor`:

```php
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Workflow;

$workflow = Workflow::make()
    ->setExecutor(new AsyncExecutor())
    ->addNodes([
        new AnalyzeImageForkNode(),
        new ExtractStructuredDataNode(),
        new GenerateDescriptionNode(),
        new MergeAnalysisNode(),
    ]);
```

`AsyncExecutor` is a drop-in replacement — it runs branches as concurrent Amp futures while keeping linear (non-parallel) nodes sequential as usual.

Async branch invocations receive shallow-cloned node wrappers so the mutable
execution context injected by `Node` cannot leak between fibers. Keep durable
branch data in `WorkflowState` or branch events; injected service objects remain
shared unless the application gives them their own isolation.

### AsyncWorkflow with AmpHttpClient

For fully asynchronous execution where branches make HTTP calls to AI providers concurrently, combine `AsyncExecutor` with `AmpHttpClient`:

- **`AsyncExecutor`** runs parallel branches as concurrent Amp fibers (non-blocking).
- **`AmpHttpClient`** is the async HTTP client built on `amphp/http-client`. Inject it on the provider via `->setHttpClient(new AmpHttpClient())` to ensure HTTP calls inside each branch are non-blocking.

Without `AmpHttpClient`, each branch's HTTP call would block its fiber, negating the concurrency benefit. With it, all branches make their API calls truly in parallel — a workflow that extracts structured data and generates a description simultaneously completes in the time of the slower branch, not the sum of both.

```php
use NeuronAI\HttpClient\Amp\AmpHttpClient;use NeuronAI\Providers\OpenAI\OpenAI;

$provider = (new OpenAI(getenv('OPENAI_API_KEY'), 'gpt-4o'))
    ->setHttpClient(new AmpHttpClient());
```

### Parallel Branches with Interruptions

Parallel branches expose one interruption at a time. The normal executor stops
at the first interruption. AsyncExecutor lets nodes already running finish and
persist their result or interruption, then starts no further nodes. Streams are
drained through that node's terminal result. A memo write is durable but does
not suspend the node midway through its invocation.

Concurrent interruptions are persisted on their branch steps and exposed in
arrival order. The current request blocks later requests, including deadlines.
Deferred deadlines retain their original value and are evaluated only once the
request becomes current. No live fibers survive a segment. Completed work is
replayed from persistence, so a fresh process can continue the run.

```php
$state = $workflow->run();

if ($state->isInterrupted()) {
    $request = $state->getInterruptRequest();
    // Collect an answer for this request, then continue the same workflow.
    $state = $workflow->run(ExecutionRequest::resume(['answer' => $decision]));
    // The result may expose the next request.
}
```

Use `memoize()` inside branch nodes for expensive operations that should not
re-run after continuation:

```php
class ExtractStructuredDataNode extends Node
{
    public function __invoke(ExtractStructuredDataEvent $event, WorkflowState $state): StopEvent
    {
        $data = $this->memoize('fetch_image', fn() => $this->fetchExpensiveImageData());

        $this->interruptIf(
            $this->needsApproval($data),
            new ApprovalRequest('Review extracted data', [/* Action[] */])
        );

        return new StopEvent(result: $data);
    }
}
```

## Workflow vs Agent

**Use Workflow when:**
- You need complete control over the execution flow
- Building custom orchestration patterns
- Need complex branching/looping logic
- Want to run multiple agents in parallel for heavy tasks
- Want to use individual components (audio providers, embeddings, etc.) independently

**Use Agent when:**
- Building chat-based applications
- Need tool calling
- Want built-in features (chat history, streaming, structured output)
- Following common conversational patterns
