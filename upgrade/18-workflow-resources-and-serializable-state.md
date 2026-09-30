# Upgrade: Nodes and middleware receive workflow resources; state and events are serializable data

## Summary

4.x writes every run to the workflow's persistence while it executes, not only when it pauses. It serializes the start event, the event and the state of every step, and every value a `checkpoint()`/`memoize()` closure returns. It does this with the workflow's serializer (PHP `serialize()` by default), for the default `InMemoryPersistence` too and for runs that never pause. 3.x serialized the state only when a run was interrupted. Two things follow:

- A closure, `PDO` or other connection, HTTP/SDK client, logger, generator or anonymous class in the state or in an event now makes the run throw when it starts or at its first step, for example `Exception: Serialization of 'Closure' is not allowed`. A stream or file handle is silently stored as `0`.
- Services a run uses belong in `NeuronAI\Workflow\WorkflowResources`. The workflow builds them from a `setResources()` factory or a `resources()` hook once for every execution segment (a run, and each continuation after a pause). It never persists them, and it passes them to every node and every middleware.

| 3.x | 4.x |
|---|---|
| `before(NodeInterface $node, Event $event, WorkflowState $state): void` | `before(NodeInterface $node, Event $event, WorkflowState $state, WorkflowResources $resources): void` |
| `after(NodeInterface $node, Event $result, WorkflowState $state): void` | `after(NodeInterface $node, Event $result, WorkflowState $state, WorkflowResources $resources): void` |
| `run(Event $event, WorkflowState $state): Generator\|Event` on `NodeInterface` and `Node` | `run(Event $event, WorkflowState $state, WorkflowResources $resources): Generator\|Event` |
| services stored in the state or in events | `setResources(fn (): WorkflowResources => ...)` or `protected function resources(): WorkflowResources`, read from an optional third `__invoke()` parameter. Existing two-parameter nodes need no change |
| `$workflow->resolveState()` | the `WorkflowState` that `run()` returns (`getReturn()` of the `events()` generator) |
| `setState($s)` / `make($id, $s)`: `$s` was the run's state object | a seed, copied at the start of every run |
| `$state->get('k', $default)` returned `$default` for a stored `null` | returns the stored `null` |
| instances passed to `addNode()`, `addMiddleware()`, `addGlobalMiddleware()` ran as they were | copied at the start of every execution segment |
| middleware keys, abstract event types and missing state types failed late or never | checked before any node runs, on every run, continuation and `export()` |

Data the app stored with 3.x is not affected by this guide. Guide 14 covers runs paused under 3.x.

### Leave these to their guides

- **Middleware that works on agent nodes: guide 27.** Guide 27 converts it to `AgentMiddleware`. Such middleware checks `$state instanceof AgentState`, reads `$state->getChatHistory()`, edits an `AIInferenceEvent` or `ToolCallEvent`, or extends `Summarization` or `ToolSearchMiddleware`. Leave it untouched here, including its three-parameter `before()`/`after()`.
- **`NeuronAI\Testing\FakeMiddleware`: guide 56.** Guide 56 covers its calls and how tests register it.
- **Agent and RAG subclasses: guide 26 for their nodes, guide 23 for `$agent->resolveState()`.** Guide 26 covers built-in node constructors, `ChatNode`/`ToolNode`/`PreProcessNode` subclasses and direct `run()` calls on agent nodes.
- **`setWorkflowContext()` on classes implementing `NodeInterface`: guide 16**, already applied.
- **Member name collisions: guide 57.** App members named `$resources`, `resources()` or `setResources()` on Workflow, Agent or RAG subclasses now collide with framework members, and guide 57 renames them. Do not add such members for anything but the resources hook.

## What to Search For

Run from the application root:

```bash
# 1. Middleware: implementations, overrides and direct calls (Case 1)
grep -rnE 'WorkflowMiddleware|function (before|after)[[:space:]]*\(|->(before|after)\(' --include='*.php' --exclude-dir=vendor .

# 2. Node run() overrides, NodeInterface implementations and direct run() calls with arguments (Case 2)
grep -rnE -e 'NodeInterface|function run[[:space:]]*\(' -e '->run\((.+,|[[:space:]]*$)' --include='*.php' --exclude-dir=vendor .

# 3. Values that reach the state, the events and memoized results (Case 3)
grep -rnE -e '[sS]tate->set\(' -e 'new [A-Za-z0-9_\\]*State\(' -e 'state:|setState\(|setStartEvent\(' -e 'function (state|startEvent)[[:space:]]*\(' -e '->(checkpoint|memoize)\(' -e '(extends|implements) [^{]*(State|Event)([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .

# 4. State reads: resolveState(), $this->state in subclasses, get() with a default (Cases 4 and 5)
grep -rnE -e 'resolveState\(' -e '[$]this->state([^A-Za-z0-9_(]|$)' -e '([sS]tate[A-Za-z0-9_]*|(run|getReturn)\([^)]*\))->get\([^,()]+,' --include='*.php' --exclude-dir=vendor .

# 5. Registered nodes and middleware, and node classes (Cases 6 and 7)
grep -rnE -e 'add(Global)?Middleware\(|addNodes?\(' -e 'function (nodes|middleware|globalMiddleware)[[:space:]]*\(' -e '(extends [A-Za-z0-9_\\]*Node|implements [^{]*NodeInterface)([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .

# 6. Workflows of the app's own that run agent nodes (Case 8)
grep -rnE -e 'new (ChatNode|StreamingNode|StructuredOutputNode|ToolNode|ParallelToolNode)\(' -e 'new AIInferenceEvent\(|setChatHistory\(' --include='*.php' --exclude-dir=vendor .
```

Follow the hits:

- **Search 1:** keep classes that implement `WorkflowMiddleware` directly or through an app base class or interface, their subclasses, and `->before(`/`->after(` calls on those objects (usually in tests). Skip the agent middleware listed above.
- **Search 2:** keep `run()` declared in classes that extend `Node` or implement `NodeInterface`, and `->run(` calls with two arguments on a node object. A workflow's `run()` takes at most one argument.
- **Search 3:** open each hit and look at the value or property type (Case 3). Also check what the app passes to `make()`/`new` as a workflow's second argument, and every event class the app's nodes return.
- **Search 4:** keep receivers that are a Workflow (for `resolveState()`), a Workflow subclass (for `$this->state`), or a `WorkflowState`/`AgentState` (for `get()`). Also check variables that hold the result of a workflow's `run()`. Nodes' `$this->state` was handled by guide 16.
- **Search 5:** check each registration (Cases 6 and 7) and the first `__invoke()` parameter of each node class (Case 7).
- **Search 6:** keep hits in classes or scripts that build a plain `Workflow` (or a Workflow subclass that is not an Agent or RAG). `setChatHistory(` on an Agent is guide 31's.

Running each workflow once, for example through its tests, surfaces every Case 7 error and most Case 3 errors, because they throw at the start of the run or at its first step. If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: A middleware implementing `WorkflowMiddleware`

A 3.x middleware no longer loads: `Declaration of LoggingMiddleware::before(...) must be compatible with NeuronAI\Workflow\Middleware\WorkflowMiddleware::before(..., NeuronAI\Workflow\WorkflowResources $resources): void`.

Before (3.x):

```php
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;
use Psr\Log\LoggerInterface;

class LoggingMiddleware implements WorkflowMiddleware
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        $this->logger->info('Executing: ' . $node::class);
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        $this->logger->info('Completed: ' . $node::class);
    }
}

// in a test
$middleware->before($node, new StartEvent(), new WorkflowState());
```

After (4.x):

```php
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;
use Psr\Log\LoggerInterface;

class LoggingMiddleware implements WorkflowMiddleware
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function before(NodeInterface $node, Event $event, WorkflowState $state, WorkflowResources $resources): void
    {
        $this->logger->info('Executing: ' . $node::class);
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state, WorkflowResources $resources): void
    {
        $this->logger->info('Completed: ' . $node::class);
    }
}

// in a test
$middleware->before($node, new StartEvent(), new WorkflowState(), new WorkflowResources());
```

1. Add `WorkflowResources $resources` as the fourth parameter of `before()` and `after()` in every middleware class, abstract base middleware and subclass override. Import `NeuronAI\Workflow\WorkflowResources`. A middleware that does not need the resources ignores the parameter.
2. Pass `new WorkflowResources()` as the fourth argument wherever app code or a test calls `before()`/`after()` directly.

### Case 2: A node that overrides `run()`, implements `NodeInterface`, or is run directly

`run()` takes the resources as a third parameter and `Node::run()` forwards them to `__invoke()`. A 3.x override no longer loads, and a two-argument call throws `ArgumentCountError`.

Before (3.x):

```php
use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class TrackedNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        return new StopEvent();
    }

    public function run(Event $event, WorkflowState $state): Generator|Event
    {
        $state->set('last_node', static::class);

        return parent::run($event, $state);
    }
}

// in a test
$result = (new SummarizeNode())->run(new StartEvent(), new WorkflowState());
```

After (4.x):

```php
use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;

class TrackedNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        return new StopEvent();
    }

    public function run(Event $event, WorkflowState $state, WorkflowResources $resources): Generator|Event
    {
        $state->set('last_node', static::class);

        return parent::run($event, $state, $resources);
    }
}

// in a test
$result = (new SummarizeNode())->run(new StartEvent(), new WorkflowState(), new WorkflowResources());
```

- A class implementing `NodeInterface` directly declares the same `run(Event $event, WorkflowState $state, WorkflowResources $resources): Generator|Event`.
- In a direct call, pass the resources class that the node's `__invoke()` declares as its third parameter (Case 3). A node with two parameters ignores them.

### Case 3: Services in the state, in events or in memoized values

Review every value that reaches the state or an event:
- values passed to `$state->set()`;
- `new WorkflowState([...])`/`new AgentState([...])` seeds, `setState()`, `make()`'s second argument, `state()` hooks;
- properties of `WorkflowState`/`AgentState` subclasses;
- properties of the start event and of every event a node returns, `StopEvent` subclasses included;
- values returned from `checkpoint()`/`memoize()` closures.

Move each non-serializable value to the resources: closures, connections, HTTP/SDK clients, loggers, generators, anonymous classes, streams and file handles, and any object that holds one of them. Keep data in the state and events: IDs, scalars, arrays and serializable value objects.

Before (3.x; construction and `run()` as guides 12 and 13 left them):

```php
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

class FetchOrderNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $order = $state->get('shop')->findOrder($state->get('order_id'));
        $state->set('total', $order->total);

        return new StopEvent();
    }
}

$state = Workflow::make('order:' . $orderId, new WorkflowState(['shop' => $shop, 'order_id' => $orderId]))
    ->addNode(new FetchOrderNode())
    ->run();
```

After (4.x):

```php
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;

class FetchOrderNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state, WorkflowResources $resources): StopEvent
    {
        $order = $resources->get('shop')->findOrder($state->get('order_id'));
        $state->set('total', $order->total);

        return new StopEvent();
    }
}

$state = Workflow::make('order:' . $orderId, new WorkflowState(['order_id' => $orderId]))
    ->setResources(fn (): WorkflowResources => new WorkflowResources(['shop' => $shop]))
    ->addNode(new FetchOrderNode())
    ->run();
```

A Workflow subclass that put a service into its state moves it to the `resources()` hook:

Before (3.x):

```php
protected function state(): WorkflowState
{
    return new WorkflowState(['shop' => $this->shop]);
}
```

After (4.x):

```php
use NeuronAI\Workflow\WorkflowResources;

protected function resources(): WorkflowResources
{
    return new WorkflowResources(['shop' => $this->shop]);
}
```

An event that carried a service keeps only its data. The node that handles it reads the service from the resources.

Before (3.x):

```php
class ImportRequested extends StartEvent
{
    public function __construct(public PDO $db, public string $file)
    {
    }
}
```

After (4.x):

```php
class ImportRequested extends StartEvent
{
    public function __construct(public string $file)
    {
    }
}
```

1. Inside nodes, read services from a third `__invoke()` parameter typed `WorkflowResources`. Middleware get the same object as their fourth argument (Case 1). `WorkflowResources` has `get(string $key, mixed $default = null)`, `has()` and `set()`.
2. Typed properties are an option: a subclass such as `class OrderResources extends WorkflowResources { public function __construct(public readonly ShopClient $shop) { parent::__construct(); } }`, returned from `protected function resources(): OrderResources` or from the `setResources()` factory. Nodes then type their third parameter `OrderResources` and read `$resources->shop`. If the workflow provides a different class, the graph fails before any node runs: `__invoke method needs OrderResources, but the workflow provides NeuronAI\Workflow\WorkflowResources`.
3. The factory and the hook run once per execution segment, so a continuation gets fresh objects. Build the services there, or capture long-lived ones as above.
4. A node that the app constructs with the service at hand may take it as a constructor argument instead (`new FetchOrderNode($shop)`), because nodes are never persisted.
5. On an Agent or RAG, add the service to the agent's own resources:

   ```php
   use NeuronAI\Agent\AgentResources;

   protected function resources(): AgentResources
   {
       $resources = parent::resources();
       $resources->set('shop', $this->shop);

       return $resources;
   }
   ```

6. A `checkpoint()`/`memoize()` closure must return data, not a client or connection.
7. A value object that must stay in the state but holds a non-serializable member: exclude that member in `__serialize()`/`__unserialize()` and rebuild it when it is needed.

### Case 4: Reading the state after a run, seeds and `$this->state`

`resolveState()` is removed. The state object given to `setState()` or to `make()` is a seed: 4.x copies it at the start of every run, and the copy is the run's state. Code that kept a reference to the seed, or read `resolveState()`, never sees the run's changes.

Before (3.x; `run()` and construction as guides 12 and 13 left them):

```php
$state = new WorkflowState(['order_id' => $orderId]);
$workflow = OrderWorkflow::make('order:' . $orderId, $state);
$workflow->run();

$total = $state->get('total');
$total = $workflow->resolveState()->get('total');
```

After (4.x):

```php
$state = OrderWorkflow::make('order:' . $orderId, new WorkflowState(['order_id' => $orderId]))->run();

$total = $state->get('total');
```

- When streaming, read the state from the generator after the loop: `$stream = $workflow->events(); foreach ($stream as $event) { ... } $state = $stream->getReturn();`.
- `resolveState()->set(...)` before a run: put the values in the seed (`make($id, new WorkflowState([...]))` or `setState(...)`). A seed replaces the `state()` hook for that instance.
- A Workflow subclass that assigned `$this->state` declares the state in the hook instead:

  Before (3.x):

  ```php
  public function __construct(?string $workflowId = null)
  {
      parent::__construct($workflowId);
      $this->state = new OrderState();
  }
  ```

  After (4.x):

  ```php
  protected function state(): WorkflowState
  {
      return new OrderState();
  }
  ```

  Reads of `$this->state` in a subclass become the state that `run()` returns, or the `$state` argument inside nodes and middleware. An override of `resolveState()` is never called: move its logic into `state()`.
- Running the same instance again starts from the seed or the `state()` hook again. 3.x continued with the previous run's state object. If the app relied on that, seed the next run explicitly with the state the previous run returned: `$workflow->setState($previous)->run()`. The seed is copied and keeps its class; its status and run metadata are reset when the run starts.
- `$agent->resolveState()` on an Agent or RAG: leave it for guide 23.

### Case 5: `WorkflowState::get()` returns a stored `null`

3.x returned the default when the key held `null` (`$this->data[$key] ?? $default`). 4.x returns the default only when the key is missing. This applies to `AgentState` too.

Before (3.x):

```php
$discount = $state->get('discount', 0.0);
```

After (4.x):

```php
$discount = $state->get('discount') ?? 0.0;
```

Rewrite a `get()` call with a default when any `set()` for that key can store `null`. The `??` form behaves exactly like 3.x, so use it whenever you are unsure. Leave calls without a default unchanged.

### Case 6: Registered nodes and middleware are copied for every execution segment

3.x ran the instances you registered, for every run. 4.x clones each node passed to `addNode()`/`addNodes()` and each middleware passed to `addMiddleware()`/`addGlobalMiddleware()` at the start of every execution segment: every `run()`/`events()` and every continuation. The copy is shallow. Values a run writes on the object's own properties land on the copy, so the instance the app holds stays unchanged. Nodes and middleware returned by the `nodes()`, `middleware()` and `globalMiddleware()` hooks are used as returned, and the hooks are called for every segment.

Before (3.x; `run()` as guide 12 left it):

```php
$timing = new TimingMiddleware();
$workflow->addGlobalMiddleware($timing);
$workflow->run();

$report = $timing->timings;   // filled by the run in 3.x, empty in 4.x
```

After (4.x):

```php
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;

$timing = new TimingMiddleware();
$workflow->addGlobalMiddleware(fn (): WorkflowMiddleware => $timing);
$workflow->run();

$report = $timing->timings;
```

- Register a factory that returns the instance wherever app code reads a registered node's or middleware's properties after the run: `addMiddleware(ChargeNode::class, fn (): WorkflowMiddleware => $timing)`, `addNode(fn (): NodeInterface => $node)` (import `NeuronAI\Workflow\NodeInterface`).
- Alternatively, write the collected data to an object the middleware receives in its constructor, because the shallow copy shares it. Data the rest of the run needs belongs in the state.
- A node or middleware that cached something on its own properties from one run to the next starts empty in every segment. Move the cache into an injected service.

### Case 7: The graph is checked before any node runs

Every `run()`, `events()`, continuation and `export()` builds and checks the whole graph first, including nodes the run never reaches. Each problem throws a `NeuronAI\Exceptions\WorkflowException`:

| Message | Cause | Fix |
|---|---|---|
| `Middleware is registered for 'X', which is not a node class.` | An `addMiddleware()` or `middleware()` key that is not a class implementing `NodeInterface`. 3.x matched it with `instanceof`, so a marker interface worked and any other key was silently ignored. | For a marker interface that nodes implement, make it `extends NeuronAI\Workflow\NodeInterface`, or register the middleware for an array of node classes. For a key that matched no node (a typo, an event class), the middleware never ran in 3.x: remove the registration and ask the developer which node it was meant for. |
| `Failed to validate X: First parameter of __invoke method must be a concrete event class, Y can never be routed` | The node's first `__invoke()` parameter is an interface or abstract class. Events are routed by their exact class, so this node never ran in 3.x. | Remove the node from the workflow and report it to the developer. If they want it to handle the concrete events, write one node per concrete event class. |
| `Failed to validate X: __invoke method needs Y, but the workflow provides Z` | The node's second parameter is a `WorkflowState` subclass the workflow does not provide. 3.x failed only when that node ran. The same message names a resources subclass the workflow does not provide (Case 3). | Provide it: return it from the `state()` hook or pass it as the seed. Otherwise type the parameter as `WorkflowState`. |

Before (3.x):

```php
interface Audited
{
}

class ChargeNode extends Node implements Audited
{
    // ...
}

$workflow->addMiddleware(Audited::class, $audit);
```

After (4.x):

```php
use NeuronAI\Workflow\NodeInterface;

interface Audited extends NodeInterface
{
}

class ChargeNode extends Node implements Audited
{
    // ...
}

$workflow->addMiddleware(Audited::class, $audit);
// or: $workflow->addMiddleware([ChargeNode::class, RefundNode::class], $audit);
```

`__invoke()` may declare two or three parameters. A third parameter must be `WorkflowResources` or a subclass of it.

### Case 8: A workflow of your own that runs agent nodes

This covers a plain `Workflow` (or a Workflow subclass that is not an Agent or RAG) that registers `ChatNode`, `StreamingNode`, `StructuredOutputNode`, `ToolNode` or `ParallelToolNode`. In 4.x these nodes read the provider, chat history, instructions and tools from `NeuronAI\Agent\AgentResources`. `ChatNode` and `StructuredOutputNode` read the run's request from `AgentState::$request`, which `AgentStartNode` builds from an `AgentStartEvent`, and they finish with an `AgentOutputEvent` that `AgentEndNode` turns into the `StopEvent`.

Before (3.x; construction and `run()` as guides 12 and 13 left them):

```php
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Workflow;

$event = new AIInferenceEvent('Be helpful', $tools);
$event->setMessages(new UserMessage('Hello'));

$state = new AgentState();
$state->setChatHistory($history);

$state = Workflow::make($workflowId, $state)
    ->setStartEvent($event)
    ->addNodes([new ChatNode($provider), new ToolNode()])
    ->run();
```

After (4.x):

```php
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Nodes\AgentEndNode;
use NeuronAI\Agent\Nodes\AgentStartNode;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolRegistry;
use NeuronAI\Workflow\Workflow;

$store = new InMemoryMessageStore();

$state = Workflow::make($workflowId, new AgentState())
    ->setStartEvent(new AgentStartEvent([new UserMessage('Hello')]))
    ->setResources(fn (): AgentResources => new AgentResources(
        $provider,
        new ChatHistory($store, $workflowId),
        new SystemMessage('Be helpful'),
        new ToolRegistry($tools),
    ))
    ->addNodes([new AgentStartNode(), new ChatNode(), new ToolNode(), new AgentEndNode()])
    ->run();
```

1. Replace the start event: the messages given to `setMessages()` become `new AgentStartEvent([...$messages])`. The `AIInferenceEvent` instructions become `new SystemMessage($instructions)` and its tools become `new ToolRegistry($tools)`, the third and fourth `AgentResources` arguments. The provider is the first argument.
2. Chat history:
   - If the 3.x code called `$state->setChatHistory($history)`, pass that history as the second `AgentResources` argument and leave its construction as it is. Guide 31 turns the 3.x history class into a `ChatHistory` over a message store.
   - Without that call, 3.x used an in-memory history. Create the `InMemoryMessageStore` once, outside the factory as shown, so that a continuation sees the same conversation.
   - In both cases, delete the `setChatHistory()` call and seed the workflow with `new AgentState()`.
3. Rewrite the node list:

   | 3.x | 4.x |
   |---|---|
   | `new ChatNode($provider)` | `new ChatNode()` |
   | `new StreamingNode($provider)` | `new ChatNode()`, and start with `new AgentStartEvent($messages, new AgentRunOptions(stream: true))` |
   | `new StructuredOutputNode($provider, Person::class, $maxTries)` | `new StructuredOutputNode()`, and start with `new AgentStartEvent($messages, new AgentRunOptions(outputClass: Person::class, maxRetries: $maxTries))` |
   | `new ToolNode(...)`, `new ParallelToolNode(...)` | same arguments (guide 4 covers the error handler callback) |
   | none | add `new AgentStartNode()` and `new AgentEndNode()` |

   `AgentRunOptions` is `NeuronAI\Agent\AgentRunOptions`. Take `maxRetries:` from the 3.x `StructuredOutputNode` `$maxTries` argument only. Drop the 3.x `AIInferenceEvent` `$maxRetries` argument: no 3.x node read it.
4. In a Workflow subclass, override `protected function resources(): AgentResources` and return the same `AgentResources` instead of calling `setResources()`.
5. The returned state is an `AgentState`, typed `WorkflowState`. Narrow it before reading the answer (`if (!$state instanceof AgentState) { throw new \LogicException('Expected AgentState'); }`), then call `$state->getMessage()`. The conversation is in the message store, not on the state.

Run it once. It must not throw any of these:
- `No nodes found that handle ...`
- `No node found that handle event: NeuronAI\Agent\Events\AgentOutputEvent`
- `__invoke method needs NeuronAI\Agent\AgentResources, but the workflow provides NeuronAI\Workflow\WorkflowResources`

## Checklist

- [ ] Every `WorkflowMiddleware` `before()`/`after()` declares `WorkflowResources $resources` as its fourth parameter, and every direct call passes one. Agent middleware is left for guide 27.
- [ ] Every `run()` override and `NodeInterface` implementation declares `run(Event $event, WorkflowState $state, WorkflowResources $resources)`, and every direct `run()` call on a node passes the resources.
- [ ] No state, seed, `state()` hook, event, state or event property, or `checkpoint()`/`memoize()` result holds a closure, connection, client, logger, generator, anonymous class, stream or an object holding one. Services come from `setResources()`, a `resources()` hook or a node constructor.
- [ ] Search 4 finds no `resolveState(` on a Workflow and no `$this->state` in a Workflow subclass. No code reads a seed object after the run.
- [ ] Every `get('k', $default)` on a state whose key can hold `null` is written `get('k') ?? $default`.
- [ ] Registered nodes and middleware that app code or tests read after a run are registered through a factory (`fn (): WorkflowMiddleware => $instance`).
- [ ] No `addMiddleware()`/`middleware()` key is a non-node class, no node's first `__invoke()` parameter is abstract or an interface, and every state or resources class a node declares is provided. Removed registrations and nodes were reported to the developer.
- [ ] Every custom workflow of agent nodes starts with an `AgentStartEvent`, registers `AgentStartNode` and `AgentEndNode`, and provides `AgentResources`.
- [ ] Each workflow runs once (through its tests) without a serialization error or a graph error.
