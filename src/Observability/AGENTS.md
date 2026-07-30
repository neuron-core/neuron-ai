# Observability Module

PSR-14 event dispatching for monitoring. Each Workflow instance owns its own
dispatcher — there is no global state, so concurrent workflows (long-running
workers, async branches, nested agents) are isolated by construction.

## Core

| File | Purpose |
|------|---------|
| `ObservabilityEvent.php` | Abstract base for all events. Carries `source` (the emitting component) and `branchId` (parallel branch, or null), stamped at dispatch time. `name()` returns the legacy string name ('inference-start'), derived from the class name unless overridden. |
| `ListenerRegistry.php` | PSR-14 `ListenerProviderInterface`. Class-keyed listeners, instanceof matching — subscribing to `ObservabilityEvent::class` receives every event. |
| `WorkflowEventDispatcher.php` | PSR-14 `EventDispatcherInterface`. Runs the workflow's listeners, then forwards to an optional external dispatcher. |
| `ObserverInterface.php` | **Deprecated** legacy observer contract: `onEvent(name, source, data, branchId)`. Still works via `ObserverAdapter`; removed in the next major. |
| `ObserverAdapter.php` | **Deprecated** — wraps an `ObserverInterface` as a PSR-14 listener on `ObservabilityEvent`; removed together with it. |

## Usage

```php
// PSR-style, class-keyed listeners
$workflow->subscribe(InferenceStop::class, function (InferenceStop $event) {
    // $event->source is the emitting node, $event->branchId the parallel branch (or null)
});

// Catch-all
$workflow->subscribe(ObservabilityEvent::class, fn (ObservabilityEvent $e) => $log($e->name()));

// Integrate with a host framework: forward every event to its PSR-14 dispatcher
$workflow->setEventDispatcher($symfonyEventDispatcher);

// DEPRECATED: legacy observers still work during the transition
// (LogObserver, InspectorObserver, ...) but will be removed in the next major
$workflow->observe(new LogObserver($logger));
```

Listeners are registered on the workflow **instance** and live as long as it
does — they observe every run of that instance (including resume cycles on the
same object).

## Emitting Events (internal)

The executor dispatches lifecycle events (`WorkflowStart`, `WorkflowNodeStart`,
`MiddlewareStart`, `BranchStart`, `AgentError`, ...). Nodes emit domain events
through `Node::emit()`:

```php
// Inside a node — the event object IS the payload
$this->emit(new ToolCalling($tool));
```

`emit()` accepts any object (PSR-14 semantics). `ObservabilityEvent` instances are
stamped with the emitting node as `source` and the current `branchId`. A node
running without an executor has no dispatcher and emits nothing.

To add a custom event, subclass `ObservabilityEvent` and subscribe to its class.

## Built-in Listeners

### LogListener

PSR-3 logger integration as a PSR-14 listener. Logs every event's `name()` with
per-event-class serialized context; override the protected `serialize*` methods
to customize.

```php
$workflow->subscribe(ObservabilityEvent::class, new LogListener($psrLogger));
```

### LogObserver (deprecated)

Legacy `ObserverInterface` variant of `LogListener` (a thin subclass), registered
via the deprecated `observe()`. Use `LogListener` instead.

## Events (`Events/`)

One class per lifecycle point. The class is the dispatch identity; `name()`
provides the legacy string name for observers/loggers (overridden where it
can't be derived from the class name, e.g. `AgentError` → 'error',
`Retrieving` → 'rag-retrieving').

## Dependencies

`psr/event-dispatcher` only.
