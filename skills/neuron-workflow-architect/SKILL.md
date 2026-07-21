---
name: neuron-workflow-architect
description: Build custom Neuron AI workflows with nodes, events, middleware, and human-in-the-loop patterns. Use this skill whenever the user mentions workflows, orchestration, event-driven systems, custom agents, complex multi-step processes, human-in-the-loop patterns, or wants to build a custom agentic system from scratch. Also trigger for tasks involving node creation, event routing, workflow middleware, persistence, or interruption patterns.
---

# Neuron AI Workflow Architect

This skill helps you build custom event-driven workflows in Neuron AI. Workflows are the foundation of the entire framework - Agent and RAG are built on top of Workflow.

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

    private function validate(mixed $input): array
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

class UserValidatedEvent extends Event
{
    public function __construct(
        public readonly string $userId,
        public readonly array $userData
    ) {}
}

class ProcessCompleteEvent extends Event
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
    protected function nodes(): array
    {
        return [
            new ValidationNode(),
            new ProcessingNode(),
        ];
    }
}
```

## Workflow State

`WorkflowState` is a shared state container that persists across all nodes:

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

## Persistence and Durability

By default, workflows use `InMemoryPersistence` — results are kept in memory and lost when the process ends. To make workflows **survive crashes and resume after interruptions**, configure a persistent backend.

### How It Works

Each completed node becomes a durable **step** persisted via `PersistenceInterface`. When the workflow runs, the `LocalStepEngine` uses a **generation** counter (a monotonically increasing version number) to implement memoization:

- Steps from a **previous generation** (a prior run) are reused from persistence — they are **not re-executed**.
- Steps from the **current generation** are always re-executed.

This means: if your process crashes mid-workflow, simply re-run it with the same persistence and workflow ID. The engine will replay all completed steps from cache and resume from the point of failure.

### Persistence Backends

```php
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\EloquentPersistence;

// File system — directory is auto-created if it doesn't exist
$persistence = new FilePersistence('/path/to/storage');

// Database via PDO — requires a workflow_steps table
$persistence = new DatabasePersistence($pdo, 'workflow_steps');

// Eloquent model — requires a model with workflow_id, step_id, result columns
$persistence = new EloquentPersistence(WorkflowStep::class);
```

### Enabling Persistence

Use the `setPersistence()` shortcut — it automatically wires a `LocalStepEngine` with the chosen backend:

```php
$workflow = Workflow::make()
    ->setPersistence(new FilePersistence('/path/to/storage'))
    ->addNodes([...]);

$finalState = $workflow->run();
```

For full control, construct the executor manually (it takes the persistence
backend directly — `LocalStepEngine` is an internal detail it wires itself):

```php
use NeuronAI\Workflow\Executor\WorkflowExecutor;

$workflow = Workflow::make()
    ->setExecutor(new WorkflowExecutor(new DatabasePersistence($pdo)))
    ->addNodes([...]);
```

### Database Table Schema

When using `DatabasePersistence`, create a table with these columns:

```sql
CREATE TABLE workflow_steps (
    workflow_id VARCHAR(255) NOT NULL,
    step_id VARCHAR(255) NOT NULL,
    result TEXT NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    PRIMARY KEY (workflow_id, step_id)
);
```

For `EloquentPersistence`, the model must have `workflow_id`, `step_id`, and `result` columns.

### Workflow Lifecycle

When the workflow completes successfully, the engine calls `delete()` on the persistence layer to clean up stored steps. Interrupted workflows retain their persisted state until resumed and completed, or manually cleaned up.

## Human-in-the-Loop Patterns

Workflows support interruption for human intervention at any point.

### Interrupting a Node

```php
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\Action;

class DangerousOperationNode extends Node
{
    public function __invoke(ProcessEvent $event, WorkflowState $state): ResultEvent
    {
        // Interrupt for approval. Signature: (string $message, array $actions = [], ?DateTimeImmutable $expiresAt = null)
        $resumeRequest = $this->interrupt(new ApprovalRequest(
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

        foreach ($resumeRequest->getActions() as $action) {
            if ($action->isApproved()) {
                $this->executeAction($action->id);
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

    // Only interrupt if cost exceeds threshold
    $resumeRequest = $this->interruptIf(
        $cost > 1000,
        new ApprovalRequest(
            actions: [/* ... */],
            message: "Operation costs $${cost}. Approval required."
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
    // Present the request to the user and collect a decision
    $request = $state->getInterruptRequest();
    $workflowId = $workflow->getWorkflowId();

    // ... user approves/rejects, mutating $request ...

    // Resume — same persistence + resume token. Completed nodes are skipped;
    // only the interrupted node re-runs with $request.
    $state = Workflow::make(resumeToken: $workflowId)
        ->setPersistence($persistence)
        ->addNodes([...])
        ->run($request);
}

$result = $state->get('result');
```

## The Suspend Vocabulary (beyond approval)

Approval is the most common reason to suspend, but it is just one payload. The
suspend model has two deliberately separated axes:

- **`InterruptType` enum (closed)** — `WaitForEvent`, `SleepUntil`. Each maps to a
  scheduler capability (an event router, a timer wheel). Adding a type is a framework
  concern. You don't invent new types in app code.
- **`InterruptRequest` class hierarchy (open)** — `InterruptRequest` is abstract.
  Subclass an *existing* type to carry a richer payload; `type()` stays inherited.

Three verbs on `Node`, each returning the same request subclass back (or `null`):

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

        $payment = $wait->getPayload();               // the delivered event payload
        return new ResultEvent($payment);
    }
}
```

Resume delivers the event by passing a hydrated request to `run()`:

```php
// The caller (a webhook controller, a queue worker, a scheduler) hydrates the
// matched event into the request and resumes:
$request = new \NeuronAI\Workflow\Interrupt\WaitForEventRequest('payment.received', $paymentPayload);

$state = Workflow::make(resumeToken: $workflowId)
    ->setPersistence($persistence)
    ->addNodes([...])
    ->run($request);
```

**Timeouts are scheduler-driven.** When the deadline elapses the scheduler resumes
the workflow with the wait marked expired internally; `awaitEvent()` surfaces that as
`null`. Branch on `if ($wait === null)` — never compare the clock in the node.

### Sleep until a clock time — `sleepUntil()`

```php
// Suspend until $wakeAt. Whether and when it fires is the scheduler's job.
$this->sleepUntil($wakeAt);
```

With the default `NullScheduler` nothing fires on its own — a caller must resume. A
real scheduler (a cron/queue worker, or a cloud platform) drives the wakeup.

### Carrying a custom payload

Subclass an existing type to add typed fields. `type()` is inherited, so the
scheduler still routes it correctly:

```php
class QuotaRefreshRequest extends WaitForEventRequest
{
    public function __construct(public readonly string $customerId)
    {
        parent::__construct('quota.refreshed.' . $customerId);
    }
}

// In a node:
$req = $this->interrupt(new QuotaRefreshRequest($customerId));
// ...on resume, $req is the same QuotaRefreshRequest with getPayload() populated.
```

### The scheduler — coordination, not state

`PersistenceInterface` stores **state** (your DB); `SchedulerInterface` owns
**coordination** — when a suspended workflow wakes. Wire one optionally:

```php
$workflow = Workflow::make()
    ->setPersistence(new DatabasePersistence($pdo))   // state
    ->setScheduler(new MyQueueScheduler());            // coordination (defaults to inert NullScheduler)
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
        // Persisted before the node returns; on resume the closure is NOT re-run.
        $data = $this->memoize('fetch_data', function () {
            return $this->fetchExpensiveData();
        });

        // Might interrupt here. On resume this node re-runs, but the memoized
        // $data above is returned without re-calling fetchExpensiveData().
        $resumeRequest = $this->interruptIf(
            $needsApproval,
            new ApprovalRequest('Approve data processing', [/* Action[] */])
        );

        if ($resumeRequest->getAction('check')?->isApproved()) {
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

Nodes can return `Generator` to yield intermediate results.

```php
class ProcessingNode extends Node
{
    public function __invoke(ProcessEvent $event, WorkflowState $state): \Generator
    {
        yield new ProgressEvent("Starting process...");

        $result = $this->longRunningOperation();

        yield new ProgressEvent("Completed!");

        return new ResultEvent($result);
    }
}
```

### Consuming Streams

```php
$generator = $workflow->events();

foreach ($generator as $event) {
    if ($event instanceof ProgressEvent) {
        echo $event->message . PHP_EOL;
    }
}

$finalState = $generator->getReturn();
```

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
- **ALWAYS configure persistence when using interruptions**
- Provide clear, actionable descriptions in InterruptRequest
- Use `memoize()` to avoid re-running expensive operations across an interruption

## Common Patterns

### Sequential Processing
```php
class SequentialWorkflow extends Workflow
{
    /**
     * @return NodeInterface[]
     */
    protected function nodes(): array
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

class ExtractStructuredDataEvent extends Event
{
    public function __construct(public readonly string $imageUrl) {}
}

class GenerateDescriptionEvent extends Event
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

Branch IDs come from the array keys (`'structured'`, `'description'`). If you pass a sequential array, IDs are auto-derived from each event's short class name.

### Step 4 — Create Branch Nodes (Each Ends with StopEvent)

```php
use NeuronAI\Agent;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

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
use NeuronAI\Workflow\Executor\Amp\AsyncExecutor;use NeuronAI\Workflow\Workflow;

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

### AsyncWorkflow with AmpHttpClient

For fully asynchronous execution where branches make HTTP calls to AI providers concurrently, combine `AsyncExecutor` with `AmpHttpClient`:

- **`AsyncExecutor`** runs parallel branches as concurrent Amp fibers (non-blocking).
- **`AmpHttpClient`** is the async HTTP client built on `amphp/http-client`. Inject it on the provider via `->setHttpClient(new AmpHttpClient())` to ensure HTTP calls inside each branch are non-blocking.

Without `AmpHttpClient`, each branch's HTTP call would block its fiber, negating the concurrency benefit. With it, all branches make their API calls truly in parallel — a workflow that extracts structured data and generates a description simultaneously completes in the time of the slower branch, not the sum of both.

```php
use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\Providers\OpenAI\OpenAI;

$provider = (new OpenAI(getenv('OPENAI_API_KEY'), 'gpt-4o'))
    ->setHttpClient(new AmpHttpClient());
```

### Parallel Branches with Interruptions

Parallel branches fully support human-in-the-loop. If any branch calls
`$this->interrupt()`, the workflow pauses exactly as in the linear case — the
state is marked interrupted and the request is available via
`$state->getInterruptRequest()`. Resume is driven by step replay, so already-completed
branches are skipped and only the interrupted branch re-runs with the request.
No parallel-specific metadata is exposed.

```php
$state = $workflow->run();

if ($state->isInterrupted()) {
    $request = $state->getInterruptRequest();
    $workflowId = $workflow->getWorkflowId();

    // ... user responds, mutating $request ...

    $state = Workflow::make(resumeToken: $workflowId)
        ->setPersistence($persistence)
        ->addNodes([...])
        ->run($request);
}
```

Use `memoize()` inside branch nodes for expensive operations that should not
re-run after resume:

```php
class ExtractStructuredDataNode extends Node
{
    public function __invoke(ExtractStructuredDataEvent $event, WorkflowState $state): StopEvent
    {
        $data = $this->memoize('fetch_image', fn() => $this->fetchExpensiveImageData());

        $resumeRequest = $this->interruptIf(
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
