# Upgrade: PSR-14 observability — static EventBus removed

## Summary

The observability system moved from a **static EventBus** to a **PSR-14 event
dispatcher owned by each Workflow instance** (`psr/event-dispatcher`). Events are
now plain objects dispatched to class-keyed listeners; the string-name channel
survives only for the deprecated observer path. This is a breaking change to
five areas:

1. **`EventBus` is removed** — `EventBus::observe()`, `EventBus::emit()`, and
   `EventBus::clear()` no longer exist; there are no global observers.
2. **`Node::emit()` takes an event object** — the `emit(string $name, mixed $data)`
   signature is gone.
3. **Observability event classes extend `ObservabilityEvent`** — they carry
   `source`, `branchId`, and `name()`; `MiddlewareStart`/`MiddlewareEnd` gained a
   `$phase` constructor parameter.
4. **`observe()` / `ObserverInterface` / `LogObserver` are deprecated** — use
   `subscribe()`, `LogListener`, and `setEventDispatcher()`; removal planned for
   the next major.
5. **`NodeInterface::setWorkflowContext()` gained a `$dispatcher` parameter** —
   direct implementors must add it.

A behavioral fix rides along: listeners are registered on the workflow
**instance** and survive every run of it. Previously, scoped observers were
cleared at the end of each run, so a second `run()`/`chat()` on the same object
silently lost observability.

## 1. `EventBus` is removed

Registration happens on the workflow/agent instance — there is no global
registry anymore. Isolation between concurrent workflows is by construction
(each instance owns its dispatcher), not by `workflowId` scoping.

```php
// Before
EventBus::observe(new CustomObserver());          // global
$workflow->observe(new CustomObserver());          // scoped

// After
$workflow->subscribe(InferenceStop::class, $listener);            // class-keyed
$workflow->subscribe(ObservabilityEvent::class, $listener);       // catch-all
$workflow->observe(new CustomObserver());                          // deprecated, still works
```

To observe every agent in an application (the old global use case), register a
shared listener set from your DI container, or forward all events to your
framework's dispatcher:

```php
$workflow->setEventDispatcher($containerPsr14Dispatcher);
```

## 2. `Node::emit()` takes an event object

The event object **is** the payload (PSR-14 semantics). Any object can be
emitted; subclassing `ObservabilityEvent` additionally gets `source`/`branchId`
stamped automatically.

```php
// Before
$this->emit('tool-calling', new ToolCalling($tool));
$this->emit('my-custom-event', ['foo' => 'bar']);

// After
$this->emit(new ToolCalling($tool));
$this->emit(new MyCustomEvent(foo: 'bar'));   // class MyCustomEvent extends ObservabilityEvent
```

Listeners subscribe by event class, so custom string names are replaced by
custom event classes.

## 3. Event classes extend `ObservabilityEvent`

All classes in `NeuronAI\Observability\Events` now extend
`NeuronAI\Observability\ObservabilityEvent` (distinct from the workflow-routing
`NeuronAI\Workflow\Events\Event`). The base class carries:

- `->source` — the component that emitted the event (a Node, the Workflow),
  stamped at dispatch time.
- `->branchId` — the parallel branch identifier, or null outside branches.
- `->name()` — the legacy string name (`'inference-start'`), derived from the
  class name unless overridden.

If you constructed these events yourself, note `MiddlewareStart` and
`MiddlewareEnd` gained a `$phase` parameter (`'before'`|`'after'`), and
`BranchStart::$branchId` / `BranchEnd::$branchId` are no longer `readonly`.

## 4. `observe()` / `ObserverInterface` / `LogObserver` are deprecated

The legacy path keeps working through an internal adapter for the whole 4.x
cycle, but new code should use the PSR-14 API:

```php
// Before (deprecated)
$agent->observe(new LogObserver($psrLogger));

class CustomObserver implements ObserverInterface
{
    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        // ...
    }
}

// After
$agent->subscribe(ObservabilityEvent::class, new LogListener($psrLogger));

$agent->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event): void {
    // $event->name(), $event->source, $event->branchId, plus the event's own data
});
```

`LogListener` carries the same protected `serialize*` methods as `LogObserver`
(which is now a thin subclass of it), so serialization overrides port by
changing the parent class.

## 5. `setWorkflowContext()` gained a `$dispatcher` parameter

Only relevant if you implement `NodeInterface` directly instead of extending
`Node`:

```php
public function setWorkflowContext(
    WorkflowState $currentState,
    Event         $currentEvent,
    ?array        $payload = null,
    bool          $timedOut = false,
    ?StepMemoizer $memoizer = null,
    ?EventDispatcherInterface $dispatcher = null,   // new
): void;
```

Custom `WorkflowExecutorInterface` implementations should pass
`$workflow->getEventDispatcher()` through to the nodes they run.

## New: `WorkflowInterrupted` event

A run that suspends for external input (tool approval, `awaitEvent()`,
`sleepUntil()`) now dispatches a dedicated `WorkflowInterrupted` event carrying
the complete interrupted `WorkflowState`. Previously a suspension was invisible to
observers — only `WorkflowEnd` fired. Interruption is a scheduled pause, not a
failure, so it is deliberately **not** an `AgentError`:

```php
use NeuronAI\Observability\Events\WorkflowInterrupted;

$agent->subscribe(WorkflowInterrupted::class, function (WorkflowInterrupted $event): void {
    foreach ($event->state->getInterruptRequests() as $request) {
        $alerts->notify("Waiting for input: {$request->getMessage()}");
    }
});
```

Terminal vocabulary per run: `WorkflowEnd` alone = completed;
`WorkflowInterrupted` + `WorkflowEnd` = paused; `AgentError` + `WorkflowEnd` = failed.
