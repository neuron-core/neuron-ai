# Observability Module

PSR-14 event dispatching for monitoring what a workflow does. Each `Workflow` instance owns its own dispatcher: there is no global state, so concurrent workflows (long-running workers, async branches, nested agents) are isolated by construction. The only dependency is `psr/event-dispatcher`.

## Model

- `ObservabilityEvent` is the base of every framework event. The event class is the dispatch identity; `name()` derives a string name from it (`InferenceStart` → 'inference-start') for loggers and legacy observers, overridden only where it cannot be derived. `source` (the emitting component) and `branchId` (the parallel branch, or null) are stamped at dispatch time, not by the emitter.
- `ListenerRegistry` matches listeners with `instanceof` semantics: subscribing to `ObservabilityEvent::class` receives everything, subscribing to a base class receives its subclasses.
- `WorkflowEventDispatcher` runs the workflow's listeners, then forwards to an optional external PSR-14 dispatcher (`setEventDispatcher()`), which is how a host framework's event system receives every event with no glue code.

```php
$workflow->subscribe(InferenceStop::class, fn (InferenceStop $event) => $metrics->record($event->source, $event->branchId));
$workflow->subscribe(ObservabilityEvent::class, new LogListener($psrLogger));
```

Listeners are registered on the workflow instance and observe every run of it, resume cycles included.

## Emitting

The executor dispatches the lifecycle (`WorkflowStart`, `WorkflowNodeStart`/`End`, `MiddlewareStart`/`End`, `BranchStart`/`End`, `AgentError`, `WorkflowEnd`). Nodes emit domain events through `Node::emit()`, where the event object *is* the payload; `emit()` accepts any object (PSR-14 semantics) and is a no-op when the node runs without an executor. A custom event is a subclass of `ObservabilityEvent` plus a subscription to its class.

The terminal vocabulary is deliberate. `WorkflowEnd` alone means completed. `WorkflowInterrupted` (carrying the full `WorkflowState` and its active interrupt requests) followed by `WorkflowEnd` means paused for external input: a scheduled pause, not a failure, so listeners can route it away from error alerting. `AgentError` followed by `WorkflowEnd` means failed.

Memory events (`MemoryRecalling`/`MemoryRecalled`, `MemoryStoring`/`MemoryStored`) delimit the memory boundary and report counts only: no queries, recalled content, retrieval scope or thread IDs, so the default log context never leaks conversation data. A failing memory operation emits its start event and `AgentError`, never a completion event.

## Deprecated path

`ObserverInterface` (`onEvent(name, source, data, branchId)`) and `LogObserver` still work through `observe()`, which wraps them with `ObserverAdapter` as a listener on `ObservabilityEvent`. They exist only for the transition; new code uses `subscribe()` and `LogListener`.
