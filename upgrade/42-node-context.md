# Upgrade: Nodes take the state and event from their arguments

## Summary

- **`Node` no longer keeps the current state and event.** In 3.x `setWorkflowContext()` stored them in the protected
  `$state` and `$event` properties, and a node's helpers could read them there. A node receives both as `__invoke()`
  arguments; pass them to the helpers that need them.
- **The branch ID left the workflow state.** A parallel branch no longer carries `__branchId` in its state. A node
  reads the branch it runs in from `$this->branchId`, null outside a branch. Middleware have no access to the branch.
- **`setWorkflowContext()` receives a `NodeContext`** (guide 7), which carries neither the state nor the event. Only
  relevant if a `Node` subclass overrides it.

## How to Refactor

### Case 1: Helpers reading `$this->state` or `$this->event`

Before:

```php
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

After:

```php
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

### Case 2: Reading the branch ID

Inside a node, read the property.

Before:

```php
$branch = $state->get('__branchId');
```

After:

```php
$branch = $this->branchId;
```

In 3.x every branch had its own copy of the state, so the key was right even when two branches reached the same node.
The property belongs to the node, which the branches share: when branches run concurrently (`AsyncExecutor` in 3.x,
`AsyncBranchRunner` after guide 43), a node reached by two branches at once can read the other branch's ID after an
await. Give each branch its own event and node, or run the branches one after another.

A middleware that read the branch from the state has no replacement. Move the branch-dependent logic into the node,
or carry what the logic needs in the event that starts the branch.

### Case 3: A `Node` subclass overriding `setWorkflowContext()`

The method receives a `NodeContext`: the resume payload, the timeout flag, the memoizer, the dispatcher and the
branch, but neither the state nor the event. Move what read them into `__invoke()`, which receives both.

Before:

```php
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

After:

```php
class ShippingNode extends Node
{
    public function __invoke(OrderPlaced $event, WorkflowState $state): ShipmentBooked
    {
        return new ShipmentBooked($state->get('carrier', 'default'));
    }
}
```

An override that still needs the rest of the context declares `setWorkflowContext(NodeContext $context)` and calls
`parent::setWorkflowContext($context)`.

## What to Search For

```
grep -rnE "\\\$this->(state|event)\b" --include="*.php" .
grep -rn "__branchId" --include="*.php" .
grep -rn "function setWorkflowContext" --include="*.php" .
```

A match on `$this->state` or `$this->event` matters only inside a class that extends `Node`.

## Checklist

- No class extending `Node` reads `$this->state` or `$this->event`.
- No code reads `__branchId` from a state; nodes use `$this->branchId`.
- Where branches run concurrently, no two branches of a fork reach the same node.
- Every `setWorkflowContext()` override in a `Node` subclass takes a `NodeContext` and calls the parent.
