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

| File | Purpose |
|------|---------|
| `Workflow.php` | Main orchestrator, builds event→node mapping, manages execution |
| `Node.php` | Base class with `interrupt()`, `checkpoint()`, context access |
| `WorkflowState.php` | Shared key-value state across nodes |
| `WorkflowHandler.php` | Execution handler with `run()` and `events()` streaming |
| `Executor/WorkflowExecutor.php` | Default sequential node executor |
| `Executor/AsyncExecutor.php` | Concurrent executor using Amp fibers for parallel branches |
| `Executor/WorkflowExecutorInterface.php` | Executor contract (`execute()` returns Generator) |
| `StartEvent.php` | Triggers workflow start (required) |
| `StopEvent.php` | Signals completion |

## Usage

```php
$handler = Workflow::make($state)
    ->addNodes([new NodeA(), new NodeB()])
    ->init();

// Stream events
foreach ($handler->events() as $event) { ... }

// Or get final state
$finalState = $handler->run();
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
    $token = $interrupt->getResumeToken();

    // ... user approves/rejects ...

    $handler = MyWorkflow::make(resumeToken: $token)->init($resumeRequest)->run();
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

## Subdirectories

- `Executor/` - `WorkflowExecutorInterface`, `WorkflowExecutor` (sequential), `AsyncExecutor` (concurrent Amp fibers), `BranchResult`
- `Interrupt/` - `InterruptRequest` (abstract), `ApprovalRequest`, `Action`, `WorkflowInterrupt`
- `Persistence/` - Storage backends for workflow state
- `Middleware/` - `WorkflowMiddleware` interface
- `Events/` - Built-in events
- `Exporter/` - Diagram export (`MermaidExporter`, `ConsoleExporter`)

## Dependencies

None. Workflow is self-contained. It's the underlying orchestration engine for the entire fraemwork.
