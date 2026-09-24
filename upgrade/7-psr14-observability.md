# Upgrade: PSR-14 observability — static EventBus removed

## Summary

The observability system moved from a **static EventBus** to a **PSR-14 event
dispatcher owned by each Workflow instance** (`psr/event-dispatcher`). Events are
now plain objects dispatched to class-keyed listeners; the string-name channel
survives only for the deprecated observer path. This is a breaking change to
six areas:

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
5. **`NodeInterface::setWorkflowContext()` receives a `NodeContext`** — it
   carries the event dispatcher; direct implementors must adopt the signature.
6. **Inspector is no longer bundled** — `inspector-apm/inspector-php` is an
   optional dependency and monitoring is never attached automatically; the
   application requires the package and subscribes its `InspectorSubscriber`.

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

## 5. `setWorkflowContext()` receives a `NodeContext`

Only relevant if you implement `NodeInterface` directly instead of extending
`Node`. The executor hands the node its execution context as one object:

```php
use NeuronAI\Workflow\NodeContext;

public function setWorkflowContext(NodeContext $context): void;
```

`NodeContext` carries the resume payload, the timeout flag, the step's memoizer,
the workflow's event dispatcher (`$context->dispatcher`) and the parallel branch
the step runs in. The event and the state are not part of it: `run()` receives
them as arguments.

Custom `WorkflowExecutorInterface` implementations should pass
`$workflow->getEventDispatcher()` in the `NodeContext` of the nodes they run.

## 6. Inspector is no longer bundled

In 3.x `inspector-apm/inspector-php` was a hard dependency of the framework and
the `EventBus` attached an `InspectorObserver` to every workflow on its own:
setting `INSPECTOR_INGESTION_KEY` was enough to get monitoring. In 4.x the
framework does not depend on Inspector and attaches nothing by default. The
integration lives in the Inspector package as a PSR-14 listener,
`Inspector\Neuron\V4\InspectorSubscriber`, and the application wires it
explicitly.

### What to Search For

```bash
grep -rn "INSPECTOR_INGESTION_KEY\|NEURON_AUTOFLUSH\|NEURON_SPLIT_MONITORING" --exclude-dir=vendor .
grep -rn "InspectorObserver\|setDefaultObserver\|Inspector\\\\Neuron" --include="*.php" --exclude-dir=vendor .
grep -n "inspector-apm" composer.json
```

An application is affected if **any** of these match — including the case where
only the environment variable is set and no PHP code mentions Inspector: that
application was monitored implicitly in 3.x and silently stops being monitored
in 4.x. If nothing matches, this step does not apply.

### Refactoring

**1. Require the package in the application** (it used to arrive transitively),
at a version that ships the `Inspector\Neuron\V4` namespace:

```bash
composer require inspector-apm/inspector-php
```

Framework integrations (`inspector-apm/inspector-laravel`,
`inspector-apm/inspector-symfony`, ...) already pull it in; make sure the
resolved version contains `Inspector\Neuron\V4\InspectorSubscriber`.

**2. Subscribe the listener on every agent and workflow that must be monitored.**
There is no global registration anymore (see section 1), so an agent without an
explicit subscription is not monitored.

```php
// Before — implicit (env variable only), or explicit:
use NeuronAI\Observability\InspectorObserver;   // or Inspector\Neuron\InspectorObserver

$agent->observe(InspectorObserver::instance());
$agent->observe(new InspectorObserver($inspector));
EventBus::setDefaultObserver(new InspectorObserver($inspector));

// After
use Inspector\Neuron\V4\InspectorSubscriber;
use NeuronAI\Observability\ObservabilityEvent;

$agent->subscribe(ObservabilityEvent::class, InspectorSubscriber::instance());
$agent->subscribe(ObservabilityEvent::class, new InspectorSubscriber($inspector));
```

`InspectorSubscriber::instance()` reads the same `INSPECTOR_INGESTION_KEY`,
`INSPECTOR_TRANSPORT`, `INSPECTOR_MAX_ITEMS`, `INSPECTOR_URL` and
`NEURON_SPLIT_MONITORING` variables as before, so the environment file does not
change. When the host framework already owns an `Inspector` instance (Laravel,
Symfony, ...), pass that instance to the constructor, as the application did
with `InspectorObserver`, so agent segments land in the current transaction.

To cover every agent the way the 3.x default did, subscribe where the
application builds its agents — a shared base class, a factory, or the DI
container — rather than at each call site:

```php
abstract class MonitoredAgent extends Agent
{
    public function __construct()
    {
        $this->subscribe(ObservabilityEvent::class, InspectorSubscriber::instance());
    }
}
```

**3. Remove what no longer exists:**

- `NeuronAI\Observability\InspectorObserver` is deleted, and
  `Inspector\Neuron\InspectorObserver` targets the 3.x observer API — do not
  keep it alive through the deprecated `observe()`.
- The `$autoFlush` argument and the `NEURON_AUTOFLUSH` variable are gone: the
  subscriber flushes at `WorkflowEnd` whenever it started the transaction
  itself, and leaves a transaction opened by the host application to the host.
- `@throws InspectorException` annotations copied from framework signatures can
  be dropped; the framework no longer throws it.

**4. Custom observers extending `InspectorObserver`** port by changing the parent
to `InspectorSubscriber`. Handlers now receive the event object instead of
`(object $source, string $event, mixed $data, ?string $branchId)`; read `$event->source` and
`$event->branchId` from it (section 3).

### Checklist

- [ ] `inspector-apm/inspector-php` is in the application's own `composer.json`
- [ ] Every agent/workflow that was monitored in 3.x — explicitly or through the
      default observer — subscribes an `InspectorSubscriber`
- [ ] No reference to `InspectorObserver`, `setDefaultObserver`, or `NEURON_AUTOFLUSH` remains
- [ ] A run of one agent produces a transaction in the Inspector dashboard

## New: `WorkflowInterrupted` event

A run that suspends for external input (tool approval, `awaitEvent()`,
`sleepUntil()`) now dispatches a dedicated `WorkflowInterrupted` event carrying
the complete interrupted `WorkflowState`. Previously a suspension was invisible to
observers — only `WorkflowEnd` fired. Interruption is a scheduled pause, not a
failure, so it is deliberately **not** an `AgentError`:

```php
use NeuronAI\Observability\Events\WorkflowInterrupted;

$agent->subscribe(WorkflowInterrupted::class, function (WorkflowInterrupted $event): void {
    if ($request = $event->state->getInterruptRequest()) {
        $alerts->notify("Waiting for input: {$request->getMessage()}");
    }
});
```

Terminal vocabulary per run: `WorkflowEnd` alone = completed;
`WorkflowInterrupted` + `WorkflowEnd` = paused; `AgentError` + `WorkflowEnd` = failed.
