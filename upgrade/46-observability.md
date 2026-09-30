# Upgrade: Observability: per-instance PSR-14 listeners replace the static EventBus

## Summary

In 3.x, events went through the static `NeuronAI\Observability\EventBus` to `ObserverInterface` observers. Observers were registered with `observe()` or `EventBus::observe()` and dropped at the end of every run. An Inspector observer was attached automatically when no other observer was registered.

In 4.x, each Workflow, Agent and RAG instance owns a PSR-14 dispatcher. Listeners are registered on the instance and stay for every later run of it. Nothing is global, and nothing is attached by default, not even Inspector.

| 3.x | 4.x |
|---|---|
| `EventBus::observe($observer, $workflowId)`, `$workflow->observe($observer)` | `$workflow->observe($observer)` (deprecated, still works) or `$workflow->subscribe($eventClass, $listener)` |
| `EventBus::observe($observer)` without a workflow ID | No equivalent (Case 2) |
| `EventBus::setDefaultObserver($observer)` | Register the observer on every instance (Case 2) |
| `EventBus::setDefaultObserver(null)`, `EventBus::clear()` | Delete the call |
| `EventBus::emit(...)`, `Node::emit(string $event, mixed $data = null)` | `Node::emit(object $event)` with an `ObservabilityEvent` subclass, or `$workflow->getEventDispatcher()->dispatch($event)` |
| `NeuronAI\Observability\Events\X` | `NeuronAI\Workflow\Observability\X`, `NeuronAI\Agent\Observability\X`, `NeuronAI\RAG\Observability\X` |
| `AgentError` | `NeuronAI\Workflow\Observability\WorkflowError` |
| `InstructionsChanging`, `InstructionsChanged`, `ToolsBootstrapped` | Removed. 3.x never dispatched them |
| `WorkflowInterrupted::$interrupt` (`WorkflowInterrupt`) | `WorkflowInterrupted::$state` (`WorkflowState`) |
| `InferenceStop::$response` (`Message`) | `NeuronAI\Providers\ProviderResponse` |
| `ToolCalling::$tool`, `ToolCalled::$tool` (`ToolInterface`) | `NeuronAI\Tools\ToolCall` |
| `AgentError::$unhandled`: always `true` | `WorkflowError::$unhandled`: always `false` |
| Event name `'workflow-interrupt'` | `'workflow-interrupted'` |
| Event name `'workflow-resume'` | Gone: `'workflow-start'` fires for every start and every resume |
| An observer exception stopped the run | A listener exception on a lifecycle event is reported as a `WorkflowError` and the run continues |
| `LogObserver::serialize*()` hooks | Removed, except `serializeData()`. Override `context(ObservabilityEvent $event): array` |
| Inspector installed with Neuron, attached automatically when `INSPECTOR_INGESTION_KEY` was set | Require `inspector-apm/inspector-php` `^3.19` and subscribe `Inspector\Neuron\V4\InspectorSubscriber` yourself |

`observe()`, `ObserverInterface` and `LogObserver` are deprecated but still work in 4.x, so this guide keeps them. Converting them to `subscribe()` with a callable listener and to `LogListener` is optional. If the app's static analysis reports deprecations, the reports for these three are expected.

Other guides own related code. Guide 16 already changed the `setWorkflowContext(NodeContext $context)` signature of classes that implement `NodeInterface` directly. Guide 20 already converted `Document` property reads in observers. Guide 34 covers the `__meta` message entries and the tool entry shape inside log contexts.

Stored data: Neuron stores and reads no observability data, so nothing needs a data migration. Log records written by 3.x stay as they are. Case 11 lists what new records look like.

## What to Search For

Run from the application root:

```bash
# 1. Event classes under the 3.x namespace, in every file type (PHP, service YAML/XML, phpstan baselines)
grep -rnE 'Observability\\+(Events\b|\{)' --exclude-dir=vendor --exclude-dir=node_modules .

# 2. The static EventBus
grep -rnE '\bEventBus\b' --include='*.php' --exclude-dir=vendor .

# 3. Renamed and removed events, and the unhandled flag
grep -rnE '\b(AgentError|InstructionsChanging|InstructionsChanged|ToolsBootstrapped)\b|->unhandled\b' --exclude-dir=vendor --exclude-dir=node_modules .

# 4. Events emitted from nodes
grep -rnE -e '->emit\(' --include='*.php' --exclude-dir=vendor .

# 5. Observers and their registrations
grep -rnE 'ObserverInterface|LogObserver|function onEvent\(|->observe\(' --include='*.php' --exclude-dir=vendor .

# 6. Events whose payload or name changed (log queries and dashboards count too)
grep -rnE '\b(WorkflowInterrupted|InferenceStop|ToolCalling|ToolCalled)\b|workflow-(interrupt|resume)\b|inference-stop|tool-call(ing|ed)\b' --exclude-dir=vendor --exclude-dir=node_modules .

# 7. LogObserver serialization overrides
grep -rnE 'function serialize[A-Z][A-Za-z]*\(' --include='*.php' --exclude-dir=vendor .

# 8. Inspector: environment, code and package
grep -rnE 'INSPECTOR_[A-Z_]+|NEURON_(AUTOFLUSH|SPLIT_MONITORING)' --exclude-dir=vendor --exclude-dir=node_modules .
grep -rnE '\bInspector\\|InspectorObserver|setDefaultObserver' --include='*.php' --exclude-dir=vendor .
grep -nE '"inspector-apm/' composer.json
```

How to follow the hits:

- Search 1: Case 1. For a group import that starts at `Observability\{`, handle only the `Events\...` members here. Search 2 covers the other members.
- Search 2: Case 2 for each `EventBus::` call. A call inside a class that implements `NodeInterface` directly goes to Case 5.
- Search 3: Case 1 for the class names, Case 6 for `->unhandled`.
- Search 4: only calls inside classes that extend `NeuronAI\Workflow\Node` count, directly or through another node class, including traits those classes use. A call whose only argument is `new SomeEvent(...)` is already done. Every other call goes to Case 4.
- Search 5: open every observer class and every class that registers observers.
  - Case 3: `->observe(` calls that run more than once on the same instance.
  - Case 6: observer bodies that read the changed payloads or names.
  - Case 7: observers that `throw` to stop a run.
  - Case 8: `LogObserver` subclasses.
  - Case 9: `->observe(` on a variable typed `WorkflowInterface`.
- Search 6: Case 6. Ignore `data-workflow-interrupt`: that is a Vercel stream part (guide 37), not an event name.
- Search 7: Case 8, but only for methods in a class that extends `NeuronAI\Observability\LogObserver`, directly or through another app class.
- Search 8: Case 10 applies if the first or the third command matches, or if the second finds an Inspector reference (a `setDefaultObserver()` call with another observer is Case 2). That includes an application where only the environment variable is set: it was monitored implicitly in 3.x.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: References to the 3.x event classes

Class names stay the same. Only the namespace changes, except for `AgentError`:

| 3.x `NeuronAI\Observability\Events\...` | 4.x |
|---|---|
| `WorkflowStart`, `WorkflowEnd`, `WorkflowNodeStart`, `WorkflowNodeEnd`, `WorkflowInterrupted`, `MiddlewareStart`, `MiddlewareEnd`, `BranchStart`, `BranchEnd` | `NeuronAI\Workflow\Observability\...` |
| `AgentError` | `NeuronAI\Workflow\Observability\WorkflowError` |
| `InferenceStart`, `InferenceStop`, `ToolCalling`, `ToolCalled`, `MessageSaving`, `MessageSaved`, `SchemaGeneration`, `SchemaGenerated`, `Extracting`, `Extracted`, `Deserializing`, `Deserialized`, `Validating`, `Validated` | `NeuronAI\Agent\Observability\...` |
| `Retrieving`, `Retrieved`, `PreProcessing`, `PreProcessed`, `PostProcessing`, `PostProcessed` | `NeuronAI\RAG\Observability\...` |
| `InstructionsChanging`, `InstructionsChanged`, `ToolsBootstrapped` | Removed |

1. Rewrite every form of reference: single imports, group imports (`use NeuronAI\Observability\Events\{A, B};`), `use NeuronAI\Observability\Events;` followed by `Events\X`, fully qualified names, `::class`, docblocks, and class-name strings in configuration files.
2. Rename `AgentError` to `WorkflowError` wherever it appears: imports, `instanceof` checks, type hints and `::class`. The properties (`$exception`, `$unhandled`) and the event name `'error'` are unchanged. Case 6 covers `$unhandled`.
3. Delete everything that handles `InstructionsChanging`, `InstructionsChanged` or `ToolsBootstrapped`: `instanceof` branches, `match` arms, imports, and `serializeInstructionsChanging()`/`serializeInstructionsChanged()` overrides. 3.x never dispatched these events, so the handlers never ran and deleting them changes no behaviour.

Before (3.x):

```php
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\Retrieved;
use NeuronAI\Observability\Events\WorkflowEnd;
```

After (4.x):

```php
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\RAG\Observability\Retrieved;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Observability\WorkflowError;
```

Then rename every `AgentError` in the file to `WorkflowError`.

### Case 2: `EventBus` calls

Remove every `use NeuronAI\Observability\EventBus;`, then handle each call.

**`EventBus::observe($observer, $workflowId)`** registers the observer on the instance that has that workflow ID:

```php
// Before (3.x)
EventBus::observe(new AuditObserver(), $agent->getWorkflowId());

// After (4.x)
$agent->observe(new AuditObserver());
```

**`EventBus::observe($observer)` without a workflow ID.** In 3.x this observer received only events that the application itself emitted with `EventBus::emit()` and no workflow ID. Framework events always carried the workflow ID and never reached it. Do not register it on agents or workflows on your own: it would start receiving events it never received. Ask the developer:
- If the app emits such events (an `EventBus::emit()` call without a workflow ID): where should those events and this observer go? Candidates are the framework's own event dispatcher, or calling the observer directly. They do not belong on an agent.
- If the app emits no such events, the observer received nothing in 3.x: should it now observe the agents and workflows (register it as for `setDefaultObserver()` below), or be deleted?

**`EventBus::setDefaultObserver($observer)`**, where `$observer` is not an Inspector observer (for Inspector, see Case 10): register the observer on every instance the app builds, once per instance (Case 3). Do it where instances are created: a factory, a container binding, or the constructor of a base class the app's agents and workflows extend. A base-class constructor keeps the parent signature:

```php
// Before (3.x), in a bootstrap file or service provider
use NeuronAI\Observability\EventBus;

EventBus::setDefaultObserver(new AuditObserver());
```

```php
// After (4.x)
use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\WorkflowState;

abstract class ObservedAgent extends Agent
{
    public function __construct(?string $workflowId = null, ?WorkflowState $state = null)
    {
        parent::__construct($workflowId, $state);
        $this->observe(new AuditObserver());
    }
}
```

If the class already has a constructor, add the `observe()` call after its `parent::__construct(...)`. Do the same in each base class the app uses (Workflow, Agent and RAG subclasses). In 3.x the default observer was attached only to runs of instances that had no observer of their own. If some instances registered their own observers, ask the developer whether those instances should get this observer too.

To send every event to the application's own PSR-14 dispatcher instead, call `$agent->setEventDispatcher($psr14Dispatcher)` at the same place. The argument must implement `Psr\EventDispatcher\EventDispatcherInterface`. The instance's own listeners run first.

**`EventBus::setDefaultObserver(null)`** (used to switch off the automatic Inspector observer) and **`EventBus::clear(...)`**: delete the call. Nothing is attached by default. A test that needs a clean slate builds a new instance.

**`EventBus::emit('name', $source, $data, ...)`**:
- In a `Node` subclass: Case 4.
- In a class that implements `NodeInterface` directly: Case 5.
- Elsewhere, with the workflow or agent instance at hand: create the event class as in Case 4, then dispatch it through the instance. Call `getEventDispatcher()` at dispatch time and do not keep the result, because a dispatcher obtained before a later `subscribe()` does not see that listener. This path does not stamp `$event->source`, so set it when listeners read it.

  ```php
  // Before (3.x)
  EventBus::emit('invoice-sent', $this, ['invoice' => $invoiceId], $workflow->getWorkflowId());

  // After (4.x)
  $sent = new InvoiceSent($invoiceId);
  $sent->source = $this;
  $workflow->getEventDispatcher()->dispatch($sent);
  ```

- In a workflow middleware (4.x middleware get no dispatcher): pass the instance to the middleware where the `middleware()` or `globalMiddleware()` hook creates it (`new AuditMiddleware($this)`), then dispatch as above.
- Without a workflow ID: see `EventBus::observe($observer)` without a workflow ID above.

Remove `@throws InspectorException` annotations that were copied from `EventBus` or other framework signatures.

### Case 3: Register each observer once per instance

In 3.x, observers registered on an instance were dropped at the end of every run, so apps that reused an instance registered them again before each run. In 4.x they stay on the instance, so repeating the registration makes every event arrive twice, three times, and so on. Move the registration to where the instance is created. This matters for container singletons, long-lived workers and loop bodies.

Before (3.x, after guide 13):

```php
foreach ($tickets as $ticket) {
    $agent->observe(new AuditObserver());
    $agent->for($ticket->threadId)->chat(new UserMessage($ticket->body));
}
```

After (4.x):

```php
$agent->observe(new AuditObserver());

foreach ($tickets as $ticket) {
    $agent->for($ticket->threadId)->chat(new UserMessage($ticket->body));
}
```

A copy made with `for()` keeps the observers registered before the copy was made.

### Case 4: A node emits a string event

`emit()` takes one event object. Give each distinct 3.x event name its own class:

1. Extend `NeuronAI\Observability\ObservabilityEvent`. Any other object reaches only listeners subscribed to its own class or a parent of it: observers registered with `observe()`, `LogListener`, `LogObserver` and the Inspector subscriber never see it.
2. Put the payload in constructor-promoted public properties. Do not declare properties named `source`, `execution` or `branchId`: they are inherited and stamped at dispatch time. The parent has no constructor to call.
3. Override `toArray()` to return what the 3.x `$data` array contained. `LogObserver` and `LogListener` log that array as the record context. Without the override they log an empty context.
4. `name()` returns the kebab-case of the class's short name: `DocumentScored` gives `'document-scored'`. When that differs from the 3.x string, and observers or log queries match the string, override it: `public function name(): string { return 'old-name'; }`.
5. Observers now receive the event object as `$data`. Replace array reads such as `$data['score']` with property reads such as `$data->score`.
6. A node that re-emitted a framework event (`$this->emit('tool-calling', new ToolCalling($tool))`) drops the string and passes a `ToolCall`: `$this->emit(new ToolCalling($call))`.

Before (3.x):

```php
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class ScoreDocumentsNode extends Node
{
    public function __invoke(DocumentsRetrieved $event, WorkflowState $state): DocumentsScored
    {
        $score = $this->scorer->score($event->documentId);
        $this->emit('document-scored', ['document' => $event->documentId, 'score' => $score]);

        return new DocumentsScored($score);
    }
}
```

```php
// In an observer
if ($event === 'document-scored') {
    $this->metrics->record($data['document'], $data['score']);
}
```

After (4.x):

```php
use NeuronAI\Observability\ObservabilityEvent;

class DocumentScored extends ObservabilityEvent
{
    public function __construct(public string $documentId, public float $score)
    {
    }

    public function toArray(): array
    {
        return ['document' => $this->documentId, 'score' => $this->score];
    }
}
```

```php
// In ScoreDocumentsNode::__invoke()
$this->emit(new DocumentScored($event->documentId, $score));
```

```php
// In the observer
if ($data instanceof DocumentScored) {
    $this->metrics->record($data->documentId, $data->score);
}
```

### Case 5: A class implementing `NodeInterface` directly emits events

Guide 16 already gave the class `setWorkflowContext(NodeContext $context)`. Keep the dispatcher and the branch from the context. Before dispatching an `ObservabilityEvent`, stamp `source` and `branchId` yourself: only `Node::emit()` does that automatically. Build the event class as in Case 4.

Before (3.x, after guide 16), members of `class AuditNode implements NodeInterface`:

```php
use NeuronAI\Observability\EventBus;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\WorkflowState;

protected ?string $branchId = null;

public function setWorkflowContext(NodeContext $context): void
{
    $this->branchId = $context->branchId;
}

public function __invoke(OrderPlaced $event, WorkflowState $state): AuditDone
{
    EventBus::emit('audit-recorded', $this, ['order' => $state->get('order_id')], $state->getWorkflowId(), $this->branchId);

    return new AuditDone();
}
```

After (4.x), the same members:

```php
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\WorkflowState;
use Psr\EventDispatcher\EventDispatcherInterface;

protected ?EventDispatcherInterface $dispatcher = null;

protected ?string $branchId = null;

public function setWorkflowContext(NodeContext $context): void
{
    $this->dispatcher = $context->dispatcher;
    $this->branchId = $context->branchId;
}

public function __invoke(OrderPlaced $event, WorkflowState $state): AuditDone
{
    $recorded = new AuditRecorded($state->get('order_id'));
    $recorded->source = $this;
    $recorded->branchId = $this->branchId;
    $this->dispatcher?->dispatch($recorded);

    return new AuditDone();
}
```

### Case 6: Observer code that reads changed payloads or names

Apply every rule that matches the observer's code:

- **`WorkflowInterrupted`**: 3.x already dispatched it. `$data->interrupt` (a `WorkflowInterrupt`) is replaced by `$data->state` (a `WorkflowState`):
  - `->interrupt->getRequest()` becomes `->state->getInterruptRequest()`, which is nullable.
  - `->interrupt->getRequest()->getMessage()` becomes `->state->getInterruptRequest()?->getMessage()`.
  - `->interrupt->getState()` becomes `->state`.
  - `->interrupt->getWorkflowId()` and `->interrupt->getResumeToken()` become `->state->getWorkflowId()`. The run is `->state->getRunId()`.
  - `->interrupt->jsonSerialize()` becomes `->state->getInterruptRequest()?->jsonSerialize()`.
  - `getNode()` and `getBranchId()`: handle `NeuronAI\Workflow\Observability\WorkflowNodeEnd` instead, where `$data->outcome === NodeOutcome::Suspended` (`NeuronAI\Workflow\Observability\NodeOutcome`). There, `$data->node` is the node class, `$source` is the node and `$branchId` is the branch.
  - `getEvent()`, `getParallelEvent()`, `getCompletedBranchResults()` and `isParallelInterrupt()` have no replacement. Delete the code, and tell the developer what it did.
- **`InferenceStop`**: `$data->response` is a `ProviderResponse`. Change `$data->response->X()` to `$data->response->message()->X()` for every `Message` method (`getContent()`, `getUsage()`, `getMetadata()`, `jsonSerialize()`, ...). The raw provider payload is also available through `->body()`, `->headers()` and `->metadata($key)`.
- **`ToolCalling` / `ToolCalled`**: `$data->tool` is a `ToolCall` record, not the tool.
  - `getName()`, `getCallId()`, `getDescription()`, `getInputs()`, `getInput($key)`, `hasResult()`, `getResult()` and `jsonSerialize()` remain. Guide 4 already converted `getResult()` reads.
  - Replace `$data->tool instanceof SomeTool` with a comparison on the name the tool registers: `$data->tool->getName() === 'search_orders'`.
  - Tool methods such as `execute()`, `getProperties()` and the setters do not exist on it. Change `ToolInterface` type hints on it to `NeuronAI\Tools\ToolCall`.
  - `ToolCalling::$fork` is unchanged.
- **`WorkflowError::$unhandled`**: 3.x always dispatched `true`, 4.x always dispatches `false`. Remove every condition on it. A `WorkflowError` is also dispatched when a listener fails and the run goes on (Case 7). To detect failed runs, check `WorkflowEnd`: `$data->state->getStatus() === WorkflowStatus::Failed` (`NeuronAI\Workflow\WorkflowStatus`, whose other cases are `Completed` and `Suspended`).
- **Event names** that observers compare with `$event`, and that log queries or alerts match:
  - `'workflow-interrupt'` becomes `'workflow-interrupted'`, or better an `instanceof WorkflowInterrupted` check.
  - `'workflow-resume'` is no longer emitted. `'workflow-start'` (`WorkflowStart`) fires for the first start and for every resume. To single out continuations, check `($data->execution->executionAttempt ?? 1) > 1`. That also counts a failed run being recovered.
  - All other 3.x names are unchanged. `'channel-error'` is new.
- **Observer arguments**:
  - `$data` is always the event object, never an array or `null`.
  - For `'workflow-node-start'`, `'workflow-node-end'` and the `'middleware-*'` events, `$source` is now the node, not the workflow. Where the observer read the workflow ID from `$source` on those events, use `$data->execution?->workflowId`. The workflow object is still the `$source` of `'workflow-start'` and `'workflow-end'`.
  - `$branchId` is still `'__main__'` outside parallel branches.
- **Tests that construct these events**: `new WorkflowInterrupted($state)` with a `WorkflowState`, `new InferenceStop($message, new ProviderResponse($responseMessage))`, and `new ToolCalling(ToolCall::make(name: 'search_orders', callId: 'call_1', inputs: []))`.

Before (3.x):

```php
use App\Neuron\Tools\SearchOrdersTool;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\WorkflowInterrupted;
use NeuronAI\Observability\ObserverInterface;

class AgentMetricsObserver implements ObserverInterface
{
    public function __construct(protected Metrics $metrics)
    {
    }

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        if ($data instanceof InferenceStop) {
            $this->metrics->add('output_tokens', $data->response->getUsage()->outputTokens ?? 0);
        }

        if ($data instanceof ToolCalled && $data->tool instanceof SearchOrdersTool) {
            $this->metrics->add('order_searches', 1);
        }

        if ($event === 'workflow-interrupt' && $data instanceof WorkflowInterrupted) {
            $this->metrics->paused($data->interrupt->getWorkflowId(), $data->interrupt->getRequest()->getMessage());
        }

        if ($event === 'workflow-resume') {
            $this->metrics->add('resumes', 1);
        }

        if ($data instanceof AgentError && $data->unhandled) {
            $this->metrics->add('failed_runs', 1);
        }
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Observability\WorkflowInterrupted;
use NeuronAI\Workflow\Observability\WorkflowStart;
use NeuronAI\Workflow\WorkflowStatus;

class AgentMetricsObserver implements ObserverInterface
{
    public function __construct(protected Metrics $metrics)
    {
    }

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        if ($data instanceof InferenceStop) {
            $this->metrics->add('output_tokens', $data->response->message()->getUsage()->outputTokens ?? 0);
        }

        if ($data instanceof ToolCalled && $data->tool->getName() === 'search_orders') {
            $this->metrics->add('order_searches', 1);
        }

        if ($data instanceof WorkflowInterrupted) {
            $this->metrics->paused($data->state->getWorkflowId(), $data->state->getInterruptRequest()?->getMessage());
        }

        if ($data instanceof WorkflowStart && ($data->execution->executionAttempt ?? 1) > 1) {
            $this->metrics->add('resumes', 1);
        }

        if ($data instanceof WorkflowEnd && $data->state->getStatus() === WorkflowStatus::Failed) {
            $this->metrics->add('failed_runs', 1);
        }
    }
}
```

### Case 7: An observer throws to stop a run

In 3.x an exception thrown by an observer stopped the run. In 4.x, an exception thrown by a listener of a lifecycle event is caught and dispatched as a `WorkflowError`, and the run continues. The lifecycle events are `WorkflowStart`, `WorkflowEnd`, `WorkflowNodeStart`, `WorkflowNodeEnd`, `MiddlewareStart`, `MiddlewareEnd`, `BranchStart`, `BranchEnd`, `WorkflowInterrupted`, `WorkflowError` and `ChannelError`. Exceptions from listeners of events that nodes emit (`InferenceStart`, `ToolCalling`, the app's own node events, ...) still propagate. Observers that throw on those need no change.

Move a guard that throws on a lifecycle event into a workflow middleware, whose `before()` runs before each node. An exception thrown there fails the run as in 3.x. Keep observers for monitoring only.

Before (3.x):

```php
use NeuronAI\Observability\ObserverInterface;

class BudgetGuard implements ObserverInterface
{
    public function __construct(protected Budget $budget)
    {
    }

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        if ($event === 'workflow-node-start' && $this->budget->exhausted()) {
            throw new BudgetExceeded();
        }
    }
}

$agent->observe(new BudgetGuard($budget));
```

After (4.x):

```php
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;

class BudgetGuard implements WorkflowMiddleware
{
    public function __construct(protected Budget $budget)
    {
    }

    public function before(NodeInterface $node, Event $event, WorkflowState $state, WorkflowResources $resources): void
    {
        if ($this->budget->exhausted()) {
            throw new BudgetExceeded();
        }
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state, WorkflowResources $resources): void
    {
    }
}

$agent->addGlobalMiddleware(new BudgetGuard($budget));
```

Guard the same nodes the observer guarded: a guard that checked `$data->node` becomes node-scoped middleware (`addMiddleware(SomeNode::class, ...)` or the `middleware()` hook). If the guard reacted to `'workflow-start'` or `'workflow-end'`, which have no node, ask the developer where the check belongs.

### Case 8: A `LogObserver` subclass overrides `serialize*()` methods

4.x `LogObserver` no longer has, and never calls, these methods: `serializeObject`, `serializeAgentError`, `serializeDeserializing`, `serializeExtracted`, `serializeWithMessage`, `serializeInferenceStop`, `serializeInstructionsChanging`, `serializeInstructionsChanged`, `serializeWithTool`, `serializeValidating`, `serializeValidated`, `serializeSchemaGeneration`, `serializeSchemaGenerated`, `serializePreProcessing`, `serializePreProcessed`, `serializePostProcessing`, `serializePostProcessed`, `serializeRetrieving`, `serializeRetrieved`, `serializeWorkflowNode`, `serializeMiddlewareStart`, `serializeMiddlewareEnd`, `serializeWorkflowStart`, `serializeWorkflowEnd`, `serializeWorkflowInterrupted`. An override that stays in place silently stops working.

1. Move every `serialize*()` override, `serializeData()` included, into one `protected function context(ObservabilityEvent $event): array`. Match on `$event::class` (the Case 1 names) and return `parent::context($event)` by default, which is the event's `toArray()`.
2. A `serializeObject()` override that added data for the app's own event classes becomes a `toArray()` override on those classes (Case 4), or a `match` arm in `context()`.
3. Keep `extends LogObserver`. Registrations with `->observe(new RedactingLogger($logger))` keep working. `LogListener` is the non-deprecated parent, but it is not an `ObserverInterface`: switch to it only together with changing every registration to `->subscribe(ObservabilityEvent::class, new RedactingLogger($logger))`.

Before (3.x):

```php
use NeuronAI\Observability\Events\Extracting;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;
use NeuronAI\Observability\LogObserver;

class RedactingLogger extends LogObserver
{
    protected function serializeWithMessage(Extracting|InferenceStart|MessageSaving|MessageSaved $data): array
    {
        return ['message' => '[redacted]'];
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Observability\Extracting;
use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Agent\Observability\MessageSaved;
use NeuronAI\Agent\Observability\MessageSaving;
use NeuronAI\Observability\LogObserver;
use NeuronAI\Observability\ObservabilityEvent;

class RedactingLogger extends LogObserver
{
    protected function context(ObservabilityEvent $event): array
    {
        return match ($event::class) {
            Extracting::class, InferenceStart::class, MessageSaving::class, MessageSaved::class => ['message' => '[redacted]'],
            default => parent::context($event),
        };
    }
}
```

### Case 9: `observe()` called on a `WorkflowInterface`

`NeuronAI\Workflow\WorkflowInterface` no longer declares `observe()`. Type the variable as `NeuronAI\Workflow\Workflow`, which `Agent` and `RAG` extend.

Before (3.x):

```php
use NeuronAI\Workflow\WorkflowInterface;

function withAudit(WorkflowInterface $workflow): WorkflowInterface
{
    return $workflow->observe(new AuditObserver());
}
```

After (4.x):

```php
use NeuronAI\Workflow\Workflow;

function withAudit(Workflow $workflow): Workflow
{
    return $workflow->observe(new AuditObserver());
}
```

### Case 10: Inspector monitoring

In 3.x, `inspector-apm/inspector-php` came with Neuron. The `EventBus` attached `InspectorObserver::instance()`, or the observer given to `setDefaultObserver()`, to every run of an instance that had no observer of its own, so `INSPECTOR_INGESTION_KEY` alone enabled monitoring. In 4.x the package is not installed, and nothing is attached. The 4.x listener is `Inspector\Neuron\V4\InspectorSubscriber`.

1. Require the package in the application's own `composer.json`, even when a framework integration such as `inspector-apm/inspector-laravel` already installs it. The subscriber, and any `Inspector\` class the app uses directly, need it:

   ```bash
   composer require "inspector-apm/inspector-php:^3.19"
   ```

   Then check that the installed subscriber targets the 4.x event classes:

   ```bash
   grep -n 'use NeuronAI\\Workflow\\Observability\\WorkflowStart;' vendor/inspector-apm/inspector-php/src/Neuron/V4/InspectorSubscriber.php
   ```

   It must print one line. Releases 3.18.1 to 3.18.3 ship a subscriber written for a pre-release 4.x namespace, which silently records nothing. If the command prints nothing, stop and report this step as blocked. Do not wire a subscriber that records nothing.

2. This step applies when 3.x monitored the app's agents or workflows: the app references an `InspectorObserver`, or `INSPECTOR_INGESTION_KEY` is set in its environment, where 3.x attached the observer automatically. If the app only uses other `Inspector\` classes, skip to step 3.

   Subscribe the subscriber on every agent, RAG and workflow that must be monitored, once per instance (Cases 2 and 3):

   | 3.x | 4.x |
   |---|---|
   | `$agent->observe(InspectorObserver::instance())` | `$agent->subscribe(ObservabilityEvent::class, InspectorSubscriber::instance())` |
   | `$agent->observe(new InspectorObserver($inspector, $autoFlush))` | `$agent->subscribe(ObservabilityEvent::class, new InspectorSubscriber($inspector))` |
   | `EventBus::setDefaultObserver(<Inspector observer>)`, or only `INSPECTOR_INGESTION_KEY` in the environment | The same `subscribe()` call on every instance, where instances are created (Case 2) |
   | `InspectorObserver::instance($key, $transport, $maxItems, $autoFlush, $splitMonitoring)` | `InspectorSubscriber::instance($key, $transport, $maxItems, $splitMonitoring)`. Drop the 4th argument or the `autoFlush:` named argument. A positional `$autoFlush` left in place would become `$splitMonitoring` |

   When 3.x monitoring came from the environment variable or from `setDefaultObserver()`, instances that registered their own observers were not monitored. Subscribing on every instance starts monitoring them: list those instances in your report.

   Before (3.x):

   ```php
   use NeuronAI\Observability\InspectorObserver;

   $agent->observe(InspectorObserver::instance());
   ```

   After (4.x):

   ```php
   use Inspector\Neuron\V4\InspectorSubscriber;
   use NeuronAI\Observability\ObservabilityEvent;

   $agent->subscribe(ObservabilityEvent::class, InspectorSubscriber::instance());
   ```

3. Remove what no longer exists:
   - `NeuronAI\Observability\InspectorObserver` and `Inspector\Neuron\InspectorObserver`. The latter targets the 3.x event classes, so do not keep it through `observe()`.
   - The `$autoFlush` argument and the `NEURON_AUTOFLUSH` variable. The subscriber flushes at `WorkflowEnd` when it started the transaction itself. Delete the variable from committed environment templates such as `.env.example`, and tell the developer it can be removed from deployed environments. Do not edit deployed configuration.
   - `catch (InspectorException ...)` blocks and `@throws InspectorException` annotations that exist only because 3.x framework methods declared them. Keep them around the app's own Inspector calls.

   `INSPECTOR_INGESTION_KEY`, `INSPECTOR_TRANSPORT`, `INSPECTOR_MAX_ITEMS`, `INSPECTOR_URL` and `NEURON_SPLIT_MONITORING` are still read.

4. A subclass of `InspectorObserver` changes its parent to `InspectorSubscriber`:
   - Handlers keep their names and become `public function <name>(<EventClass> $event): void`. For example, `inferenceStart(object $source, string $event, InferenceStart $data, ?string $branchId = null)` becomes `inferenceStart(InferenceStart $event)`, and `error(..., AgentError $data, ...)` becomes `error(WorkflowError $event)`. Read `$event->source` and `$event->branchId` (`null` outside parallel branches) instead of the arguments, and apply the Case 6 payload changes.
   - Delete `toolsBootstrapping()`/`toolsBootstrapped()` overrides.
   - Entries the subclass added to `$methodsMap` become an override of `public function __invoke(ObservabilityEvent $event): void`, which handles the app's event classes and otherwise calls `parent::__invoke($event)`.

### Case 11: Code that parses Neuron log records

Apply this case only if the app parses log records, or has dashboards or alerts built on them. Record messages are the event names (Case 6). These contexts changed:

| Record | 4.x context |
|---|---|
| `workflow-start` | One map `{EventClass: NodeClass}` (3.x: a list of one-entry maps) |
| `workflow-end` | `workflowId`, `runId`, `executionAttempt`, `status`, `state` (3.x: `state`) |
| `workflow-interrupted` (3.x `workflow-interrupt`) | `workflowId`, `runId`, `executionAttempt`, `status`, `interrupt` (3.x: `workflowId`, `request`, `message`, `node`, `branchId`) |
| `workflow-node-end`, `middleware-before-end`, `middleware-after-end` | Adds `outcome` |
| `rag-retrieving` | Adds `filters` |
| `workflow-resume` | No longer written |

Message entries and tool entries inside the contexts are covered by guide 34.

## Checklist

- Searches 1 and 3 find no `NeuronAI\Observability\Events` reference, no `AgentError`, `InstructionsChanging`, `InstructionsChanged` or `ToolsBootstrapped`, and no condition on `->unhandled`.
- Search 2 finds no `EventBus`. Every global `EventBus::observe()` without a workflow ID was decided by the developer.
- Every observer is registered once per instance, where the instance is created, and never before each run.
- No node calls `emit()` with a string. Every emitted class extends `ObservabilityEvent`, overrides `toArray()` when its data was logged, and overrides `name()` when observers or log queries match an old name that differs from the derived one.
- Classes implementing `NodeInterface` directly dispatch through `$context->dispatcher` and stamp `source` and `branchId`.
- No observer reads `WorkflowInterrupted::$interrupt`, calls a `Message` method directly on `InferenceStop::$response`, treats `ToolCalling::$tool` or `ToolCalled::$tool` as a tool object, or compares `$event` with `'workflow-interrupt'` or `'workflow-resume'` (Vercel's `data-workflow-interrupt` excepted).
- No observer throws on a lifecycle event to stop a run. Those guards are workflow middleware.
- No `LogObserver` subclass overrides a `serialize*()` method. Their logic lives in `context()`.
- No `observe()` call is made on a variable typed `WorkflowInterface`.
- If Inspector was used: `composer.json` requires `inspector-apm/inspector-php` `^3.19`, the vendor check in Case 10 prints one line, every monitored instance subscribes `InspectorSubscriber`, and nothing references `InspectorObserver`, `setDefaultObserver` or `NEURON_AUTOFLUSH`.
- Static analysis reports no unknown class or method under `NeuronAI\Observability`, `NeuronAI\Workflow\Observability`, `NeuronAI\Agent\Observability`, `NeuronAI\RAG\Observability` or `Inspector\`.
- Smoke test: one agent run produces the records the app expects (`workflow-start`, `workflow-node-start`, `inference-start`, `inference-stop`, ..., `workflow-end`) and, with Inspector, a transaction in the Inspector dashboard.
