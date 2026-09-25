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

Listeners are registered on the workflow definition. Each execution captures its dispatcher and listener registrations during setup. Adding a listener or replacing the external dispatcher affects subsequent segments, including resumes; it cannot split an active segment's lifecycle across dispatcher configurations. Supplied listener and external dispatcher objects remain shared services.

## Emitting

Each execution segment dispatches the lifecycle (`WorkflowStart`, `WorkflowNodeStart`/`End`, `MiddlewareStart`/`End`, `BranchStart`/`End`, `WorkflowInterrupted`, `AgentError`, `WorkflowEnd`). Nodes emit domain events through `Node::emit()`, where the event object *is* the payload; `emit()` accepts any object (PSR-14 semantics) and is a no-op when the node runs outside a workflow. A custom event is a subclass of `ObservabilityEvent` plus a subscription to its class.

`WorkflowEnd` reports the execution segment's final state: inspect `state->getStatus()` to distinguish completion, suspension and failure. A suspended segment emits one `WorkflowInterrupted` with the full state and its single current request, then `WorkflowEnd`. Async branches finish their running nodes before this event; deferred requests are reported only when they become current. An `AgentError` can also describe an isolated listener error, so it does not by itself establish that the run failed.

`WorkflowStart` observers read authoritative run identity and attempt from `$event->execution`; `source` remains the configured definition. End/interruption events also carry their state. Definitions have no current-state or last-run getters. Staging `submitApprovalDecisions()` or `submitToolResults()` emits no execution events; the following `run()` or `events()` emits the normal segment lifecycle. Reading an unchanged wait or replaying a retained completion without execution emits no new lifecycle events.

`ToolCalling` marks local execution or the handoff of an approved deferred call. `ToolCalled` reports its settled result. For deferred tools these events can span multiple execution segments: partial result submissions persist progress, and `AwaitToolResultsNode` emits the batch's results when all pending calls are settled. Approval submissions do not themselves imply tool completion. Events describe execution and are not a durable audit log; retries after an uncommitted step may emit them again.

`LogListener` and legacy `LogObserver` include `workflowId`, `runId`, `executionAttempt` and `status` in interruption and end records. The interruption record includes one `interrupt`; the end record retains application data under `state`. Use identity plus attempt to correlate sequential interruptions and use the explicit status for completion metrics.

Conversation retrieval uses `Retrieving`/`Retrieved`; optional ingestion uses the standard `WorkflowNodeStart`/`WorkflowNodeEnd` lifecycle for `ConversationIngestionNode`. Failures use `AgentError`.

## Deprecated path

`ObserverInterface` (`onEvent(name, source, data, branchId)`) and `LogObserver` still work through `observe()`, which wraps them with `ObserverAdapter` as a listener on `ObservabilityEvent`. They exist only for the transition; new code uses `subscribe()` and `LogListener`.
