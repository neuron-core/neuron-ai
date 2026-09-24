# Upgrade: Nodes take the state and event from their arguments

## Summary

- **`Node` no longer keeps the current state and event.** In 3.x `setWorkflowContext()` stored them in the protected
  `$state` and `$event` properties, and a node's helpers could read them there. A node receives both as `__invoke()`
  arguments; pass them to the helpers that need them.
- **The branch ID left the workflow state.** The executor no longer writes `__branchId` into the state of a parallel
  branch. A node reads the branch it runs in from `$this->branchId`, null outside a branch. Middleware have no access
  to the branch.

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

A middleware that read the branch from the state has no replacement. Move the branch-dependent logic into the node,
or carry what the logic needs in the event that starts the branch.

## What to Search For

```
grep -rnE "\\\$this->(state|event)\b" --include="*.php" .
grep -rn "__branchId" --include="*.php" .
```

A match on `$this->state` or `$this->event` matters only inside a class that extends `Node`.

## Checklist

- No class extending `Node` reads `$this->state` or `$this->event`.
- No code reads `__branchId` from a state; nodes use `$this->branchId`.
