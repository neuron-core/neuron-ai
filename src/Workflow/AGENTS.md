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

## Checkpoint

Cache expensive operations across interruptions:
```php
// Inside node
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
        new WorkflowExecutor(new LocalStepEngine(new DatabasePersistence($pdo)))
    );
$workflow->run();
```
