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

## Key Files

| File                                     | Purpose                                                                                                                |
|------------------------------------------|------------------------------------------------------------------------------------------------------------------------|
| `Workflow.php`                           | Main orchestrator, builds event→node mapping, manages execution with `run()` and `events()` streaming                  |
| `Node.php`                               | Base class with `interrupt()`, `checkpoint()`, context access                                                          |
| `WorkflowState.php`                      | Shared key-value state across nodes                                                                                    |
| `Executor/WorkflowExecutorInterface.php` | Executor contract (`execute()` returns Generator)                                                                      |
| `Executor/WorkflowExecutor.php`          | Default executor: sequential traversal with InMemoryPersistence takes a `NodeRunner` + optional `PersistenceInterface` |
| `Executor/AsyncExecutor.php`             | Extends `WorkflowExecutor`, runs parallel branches concurrently via Amp fibers                                       |
| `Executor/NodeRunner.php`                | Interface for single-node execution lifecycle                                                                          |
| `Executor/DefaultNodeRunner.php`         | Default `NodeRunner`: context → node-start → before-middleware → execute → after-middleware → node-end                 |
| `StartEvent.php`                         | Triggers workflow start (required)                                                                                     |
| `StopEvent.php`                          | Signals completion                                                                                                     |

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
// In node
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

## Checkpoint

Cache expensive operations across interruptions:
```php
// In node
$data = $this->checkpoint('key', fn() => expensiveCall());
```

## Middleware

Wrap node execution with cross-cutting concerns:

```php
$workflow->middleware(NodeClass::class, new LoggingMiddleware());
$workflow->globalMiddleware(new PerformanceMiddleware());
```

Interface: `before(NodeInterface, Event, WorkflowState)` and `after(NodeInterface, Event, result, WorkflowState)`

## Executors

The executor controls **how** the workflow graph is traversed. `Workflow` delegates to an executor via `resolveExecutor()`.

### Architecture

Two layers: **Executor** (graph traversal, parallel branch strategy, interrupt/resume, persistence) and **NodeRunner** (single-node lifecycle: middleware + invocation).

```
Workflow
  └─ WorkflowExecutorInterface (execute the graph)
       ├─ WorkflowExecutor   (default, self-contained, injectable NodeRunner + Persistence)
       │    └─ AsyncExecutor (concurrent branches via Amp fibers)
  └─ NodeRunner (single node lifecycle)
       └─ DefaultNodeRunner
```

### Choosing an executor

```php
// Default (no configuration needed)
$workflow = Workflow::make();
$workflow->run();

// Async parallel branches (requires ext-amp)
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Executor\DefaultNodeRunner;

$workflow = Workflow::make()
    ->setExecutor(new AsyncExecutor());
$workflow->run();

// Custom persistence with WorkflowExecutor
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Persistence\DatabasePersistence;

$workflow = Workflow::make()
    ->setExecutor(
        new WorkflowExecutor(new DatabasePersistence($pdo))
    );
$workflow->run();
```

### NodeRunner

The `NodeRunner` interface owns the full node lifecycle:
1. Set workflow context on the node
2. Emit `workflow-node-start`
3. Run before-middleware
4. Execute the node (unwrap Generators for streaming)
5. Run after-middleware
6. Emit `workflow-node-end`

Implement `NodeRunner` to customize node execution (e.g. tracing, error handling wrappers) independently of the graph traversal strategy.

