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
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\StopEvent;
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
use NeuronAI\Workflow\Event;

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
- Implement the `Event` interface
- Use readonly properties for immutability
- Contain all data needed by the handling node

## Creating a Workflow

### Basic Workflow

```php
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\StopEvent;

$state = new WorkflowState([
    'input' => $userData,
]);

$workflow = Workflow::make($state)
    ->addNodes([
        new ValidationNode(),
        new ProcessingNode(),
        new OutputNode(),
    ]);

$handler = $workflow->start();
$finalState = $handler->run();
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
        // Interrupt for approval
        $resumeRequest = $this->interrupt(new ApprovalRequest(
            actions: [
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
            message: 'These operations require approval'
        ));

        foreach ($resumeRequest->actions as $action) {
            if ($action->decision === ActionDecision::Approved) {
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

```php
use NeuronAI\Workflow\Persistence\FilePersistence;

$persistence = new FilePersistence('/tmp/workflows');
$workflowId = 'workflow_' . uniqid();

$workflow = Workflow::make($state, $persistence, $workflowId)
    ->addNodes([...]);

try {
    $handler = $workflow->start();
    $result = $handler->run();
} catch (WorkflowInterrupt $interrupt) {
    // Present to user
    $request = $interrupt->getRequest();
    $state = $interrupt->getState();

    // After user makes decisions:
    $resumeRequest = $this->getUserDecisions($request);
    $handler = $workflow->start($resumeRequest);
    $result = $handler->run();
}
```

## Checkpoints

Nodes can use checkpoints to cache operations happening before the interruption point.

```php
class DataProcessingNode extends Node
{
    public function __invoke(ProcessEvent $event, WorkflowState $state): ResultEvent
    {
        // When resumed,
        // $data is retrieved from checkpoint
        $data = $this->checkpoint('fetch_data', function() {
            return $this->fetchExpensiveData();
        });

        // Might interrupt here
        $resumeRequest = $this->interruptIf($needsApproval, new ApprovalRequest(...));

        if ($resumeRequest->getAction('check')->isApproved()) {
            return new ResultEvent($data);
        }

        return new AnotherEvent();
    }
}
```

## Middleware System

Middleware wraps node execution for cross-cutting concerns.

### Creating Custom Middleware

```php
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Event;

class LoggingMiddleware implements WorkflowMiddleware
{
    public function __construct(private \Psr\Log\LoggerInterface $logger) {}

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        $this->logger->info("Executing: " . $node::class);
    }

    public function after(NodeInterface $node, Event $event, Event|Generator $result, WorkflowState $state): void
    {
        $this->logger->info("Completed: " . $node::class);
    }
}
```

### Registering Middleware

```php
// Node-specific middleware
$workflow->middleware(ProcessingNode::class, new LoggingMiddleware($logger));

// Multiple middleware on one node
$workflow->middleware(ProcessingNode::class, [
    new ValidationMiddleware(),
    new LoggingMiddleware(),
]);

// Global middleware (runs on all nodes)
$workflow->globalMiddleware(new PerformanceMiddleware());
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
$handler = $workflow->start();

foreach ($handler->events() as $event) {
    if ($event instanceof ProgressEvent) {
        echo $event->message . PHP_EOL;
    }
}

$finalState = $handler->run();
```

## Checkpoint System

Checkpoint cache operation results across interruptions:

```php
class DataProcessingNode extends Node
{
    public function __invoke(ProcessEvent $event, WorkflowState $state): ResultEvent
    {
        // When resumed, $data is retrieved from checkpoint
        $data = $this->checkpoint('fetch_data', function() {
            return $this->fetchExpensiveData();
        });

        // Might interrupt here
        $resumeRequest = $this->interruptIf($needsApproval, new ApprovalRequest(...));

        if (!$resumeRequest->isApproved()) {
            // ...
        }

        // $data is retrieved from checkpoint
        $result = $this->process($data);

        return new ResultEvent($result);
    }
}
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
- Use checkpoints for operations before interruption points

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
- Use checkpoints to avoid re-running expensive operations

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
            ->setTools([/* ... */])
            ->addSystemTip('Extract structured data from the image.');

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
            ->addSystemTip('Describe the image in detail.');

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
$workflow = Workflow::make()
    ->addNodes([
        new AnalyzeImageForkNode(),
        new ExtractStructuredDataNode(),
        new GenerateDescriptionNode(),
        new MergeAnalysisNode(),
    ]);

$state = $workflow->init(new WorkflowState(['image_url' => 'https://example.com/photo.jpg']));
$result = $state->run();
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

Parallel branches fully support human-in-the-loop. If any branch calls `$this->interrupt()`, the executor throws a `WorkflowInterrupt` with parallel context:

```php
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

try {
    $result = $workflow->init()->run();
} catch (WorkflowInterrupt $interrupt) {
    if ($interrupt->isParallelInterrupt()) {
        // $interrupt->getBranchId() — which branch interrupted
        // $interrupt->getCompletedBranchResults() — results from branches that finished
        // Present interrupt to user...
    }
}

// After user responds:
$handler = $workflow->init($interrupt->getRequest());
$result = $handler->run();
// Resuming skips already-completed branches, only re-runs the interrupted one.
```

Use `Checkpoint` inside branch nodes for expensive operations that should not re-run after resume:

```php
class ExtractStructuredDataNode extends Node
{
    public function __invoke(ExtractStructuredDataEvent $event, WorkflowState $state): StopEvent
    {
        $data = $this->checkpoint('fetch_image', fn() => $this->fetchExpensiveImageData());

        $resumeRequest = $this->interruptIf(
            $this->needsApproval($data),
            new ApprovalRequest(actions: [...], message: 'Review extracted data')
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
