# Upgrade: Nodes take the state and event from their arguments

## Summary

- `Node` no longer keeps the current state and event. The protected `$state` and `$event` properties that 3.x `setWorkflowContext()` filled are gone. Read the `$event` and `$state` arguments of `__invoke()` and pass them to the helpers that need them.
- The branch ID is no longer in the state. 3.x wrote `__branchId` into each parallel branch's copy of the state. A 4.x node reads `$this->branchId` (`?string`, null outside a branch), and middleware get no branch ID. Left unchanged, `$state->get('__branchId')` fails silently: it returns null.
- `setWorkflowContext()` takes a single `NeuronAI\Workflow\NodeContext`, which carries neither the state nor the event. This affects `Node` subclasses that override it, classes implementing `NodeInterface` directly, and tests that call it.

| 3.x | 4.x |
|---|---|
| `$this->state`, `$this->event` in a node | The `$state` and `$event` arguments of `__invoke()` |
| `$state->get('__branchId')` in a node | `$this->branchId` |
| `setWorkflowContext(WorkflowState $currentState, Event $currentEvent, ?InterruptRequest $resumeRequest = null): void` | `setWorkflowContext(NodeContext $context): void` |
| `$node->setWorkflowContext($state, $event)` before running a node in a test | Nothing: a node runs without a context |

Only code changes. No stored data or database schema is involved.

## What to Search For

```bash
grep -rnE '\$this->(state|event)\b' --include='*.php' --exclude-dir=vendor .
grep -rnE '__branchId' --include='*.php' --exclude-dir=vendor .
grep -rnE 'setWorkflowContext\(' --include='*.php' --exclude-dir=vendor .
```

Follow the hits:
- `$this->state` / `$this->event`: only hits inside a class that extends `NeuronAI\Workflow\Node` count (directly, or through another node class, including built-in nodes), plus hits in traits those classes use. They go to Case 1. A class that declares its own `$state` or `$event` property is not affected only if it assigns that property itself (for example `$this->state = $state;` in `__invoke()`). A declaration that nothing in the class assigns, typically `/** @var OrderState */ protected WorkflowState $state;`, redeclared the 3.x `Node` property that `setWorkflowContext()` filled; 4.x never sets it, and reading it throws `Typed property ...::$state must not be accessed before initialization` (PHPStan does not report this). Apply Case 1 to its reads and delete the declaration. Leave `$this->state` in Workflow, Agent or RAG subclasses alone: guide 18 migrates it.
- `__branchId`: Case 2. Writes of the key in tests go to Case 5.
- `setWorkflowContext(`: a declaration in a `Node` subclass goes to Case 3. A declaration in a class implementing `NodeInterface` directly (or in an app base class that does) goes to Case 4. A call goes to Case 5 (usually in a test), but calls that pass a `NodeContext` were already converted by guide 15: leave them.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: A node reads `$this->state` or `$this->event`

Replace each read with the `__invoke()` argument, and add a parameter to every helper that needs it. Behaviour does not change: in 3.x the property and the argument were the same object.

Before (3.x):
```php
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class InvoiceNode extends Node
{
    public function __invoke(OrderPlaced $event, WorkflowState $state): InvoiceSent
    {
        return new InvoiceSent($this->total());
    }

    protected function total(): float
    {
        return array_sum($this->state->get('prices'));
    }
}
```

After (4.x):
```php
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class InvoiceNode extends Node
{
    public function __invoke(OrderPlaced $event, WorkflowState $state): InvoiceSent
    {
        return new InvoiceSent($this->total($state));
    }

    protected function total(WorkflowState $state): float
    {
        return array_sum($state->get('prices'));
    }
}
```

### Case 2: Code reads `__branchId`

Branch IDs are the keys of the `ParallelEvent` branches (guide 17 turns them into explicit string names). Pick the step that matches where the read is:

1. **Inside a node**: read the property.

   Before (3.x):
   ```php
   $branch = $state->get('__branchId');
   ```

   After (4.x):
   ```php
   $branch = $this->branchId;
   ```

2. **In a helper, service or tool that received a node's state**: have the node pass `$this->branchId` to it as an argument.

3. **In a `WorkflowMiddleware`**: `before()` and `after()` get no branch ID. If the middleware wraps a single node, move the branch-dependent logic into that node. Otherwise, give the wrapped node a public method that returns `$this->branchId` and call it on the `$node` argument. The node's branch is already set when `before()` runs and is still set in `after()`. If the middleware wraps several node classes, declare that method in an interface of your own, implement it in each of those nodes, and check `$node instanceof` that interface. Leave the `before()`/`after()` signatures as they are: guide 18 adds their fourth parameter.

   Before (3.x):
   ```php
   public function before(NodeInterface $node, Event $event, WorkflowState $state): void
   {
       $this->logger->info('Node started', ['node' => $node::class, 'branch' => $state->get('__branchId')]);
   }
   ```

   After (4.x), in the wrapped node:
   ```php
   class SummarizeNode extends Node
   {
       public function currentBranch(): ?string
       {
           return $this->branchId;
       }

       // __invoke() unchanged
   }
   ```

   and in the middleware's `before()`:
   ```php
   $this->logger->info('Node started', [
       'node' => $node::class,
       'branch' => $node instanceof SummarizeNode ? $node->currentBranch() : null,
   ]);
   ```

4. **In an observer's `onEvent()`**: use its `$branchId` argument (`'__main__'` outside a branch). Observers still work in 4.x (deprecated); guide 46 migrates their changed payloads.

5. **On the workflow's own state** (`resolveState()` in a Workflow or Agent subclass, or the state a run returns): 3.x only wrote the key into the branch copies, so this read always returned its default. Replace it with that default: `$this->resolveState()->get('__branchId', '__main__')` becomes `'__main__'`.

If branches run concurrently (3.x `AsyncExecutor`, `AsyncBranchRunner` after guide 17), a node that reads `$this->branchId` must not be reached by two branches of the same fork. Report such forks to the developer rather than restructuring them.

### Case 3: A `Node` subclass overrides `setWorkflowContext()`

Move the logic that read `$currentState` or `$currentEvent` into `__invoke()`, which receives both, then delete the override if nothing else is left in it. Where the override checked `$resumeRequest !== null`, call `$this->isResuming()` at the start of `__invoke()`. Guide 15 already turned the resume answer into what `interrupt()` returns.

Before (3.x):
```php
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class ShippingNode extends Node
{
    protected string $carrier;

    public function setWorkflowContext(WorkflowState $currentState, Event $currentEvent, ?InterruptRequest $resumeRequest = null): void
    {
        parent::setWorkflowContext($currentState, $currentEvent, $resumeRequest);
        $this->carrier = $currentState->get('carrier', 'default');
    }

    public function __invoke(OrderPlaced $event, WorkflowState $state): ShipmentBooked
    {
        return new ShipmentBooked($this->carrier);
    }
}
```

After (4.x):
```php
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class ShippingNode extends Node
{
    public function __invoke(OrderPlaced $event, WorkflowState $state): ShipmentBooked
    {
        return new ShipmentBooked($state->get('carrier', 'default'));
    }
}
```

An override that must stay takes this signature and still calls the parent, which sets the node's resume and branch data:
```php
use NeuronAI\Workflow\NodeContext;

public function setWorkflowContext(NodeContext $context): void
{
    parent::setWorkflowContext($context);
    // ...
}
```

### Case 4: A class implements `NodeInterface` directly

1. Change the declaration to `setWorkflowContext(NodeContext $context): void`. If it already takes `NodeContext`, skip to step 2.
2. Read the state and event only from the arguments of `run()` and `__invoke()` (as in Case 1), and delete the properties that stored them.
3. Keep from the context only what the node needs:
   - `$context->branchId` replaces a `__branchId` read;
   - `$context->resuming` replaces `$resumeRequest !== null`;
   - `$context->payload` (the answer array, see guide 15) replaces the answered `$resumeRequest`.

Leave `run()`'s signature alone: guide 18 adds its third parameter. Event dispatching (`EventBus::emit()`) is migrated by guide 46.

Before (3.x, after guide 15), members of `class AuditNode implements NodeInterface`:
```php
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowState;

protected WorkflowState $state;

public function setWorkflowContext(WorkflowState $currentState, Event $currentEvent, ?InterruptRequest $resumeRequest = null): void
{
    $this->state = $currentState;
}

public function __invoke(OrderPlaced $event, WorkflowState $state): AuditDone
{
    $this->audit->record([$this->state->get('order_id'), $this->state->get('__branchId')]);

    return new AuditDone();
}
```

After (4.x), the same members:
```php
use NeuronAI\Workflow\Events\Event; // still used by run()
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\WorkflowState;

protected ?string $branchId = null;

public function setWorkflowContext(NodeContext $context): void
{
    $this->branchId = $context->branchId;
}

public function __invoke(OrderPlaced $event, WorkflowState $state): AuditDone
{
    $this->audit->record([$state->get('order_id'), $this->branchId]);

    return new AuditDone();
}
```

### Case 5: Code calls `setWorkflowContext()` with a state and event

Delete the call. In 4.x a node runs without a context: it is not resuming, is outside any branch, and emits nothing. A test that simulated a branch by writing `__branchId` into the state passes the branch through a `NodeContext` instead.

Before (3.x):
```php
$node = new InvoiceNode();
$node->setWorkflowContext($state, $event);
$result = $node($event, $state);

$state->set('__branchId', 'left');
$summarizer->setWorkflowContext($state, $chunk);
$result = $summarizer($chunk, $state);
```

After (4.x):
```php
use NeuronAI\Workflow\NodeContext;

$node = new InvoiceNode();
$result = $node($event, $state);

$summarizer->setWorkflowContext(new NodeContext(branchId: 'left'));
$result = $summarizer($chunk, $state);
```

## Checklist

- No class extending `Node`, and no trait such a class uses, reads `$this->state` or `$this->event` unless it assigns that property itself, and no such class keeps a `$state` or `$event` declaration that nothing assigns.
- The `__branchId` search finds nothing.
- Every `setWorkflowContext()` declaration is `public function setWorkflowContext(NodeContext $context): void`, and overrides in `Node` subclasses call `parent::setWorkflowContext($context)`.
- No `setWorkflowContext()` call passes a state or event: calls pass `$context` to the parent or a `new NodeContext(...)`.
- Nodes that read `$this->branchId` under concurrent branches have been reported to the developer (see guide 17).
