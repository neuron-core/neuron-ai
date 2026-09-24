# Upgrade: A node's resume state is no longer exposed

## Summary

- **`NodeInterface` no longer declares `isResuming()` or `getResumeRequest()`.** In 3.x a middleware could ask
  the node it wraps whether the run was resuming and which request was being answered. The delivered answer now
  belongs to the node alone: `interrupt()` returns it on the resuming pass.
- **`Node::isResuming()` is protected.** A node can still call `$this->isResuming()`; code outside the node
  cannot.
- **`getResumeRequest()` has no replacement.** Tool approval, the main use of it, is owned by `ToolNode`
  since guide 11.

## What to Search For

```
grep -rnE "isResuming\(\)|getResumeRequest\(\)" --include="*.php" .
```

A match on `$this` inside a class that extends `Node` needs no change. A match on another object, typically the
`$node` parameter of a middleware's `before()` or `after()`, must be rewritten.

## How to Refactor

### Case 1: Middleware reacting to a resumed interruption

Move the logic into the node that interrupts. On the resuming pass, `interrupt()` returns the answer the caller
delivered.

Before:

```php
class AuditDecisions implements WorkflowMiddleware
{
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if ($node->isResuming() && $node->getResumeRequest() instanceof ApprovalRequest) {
            $this->audit->record($node->getResumeRequest());
        }
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
    }
}
```

After:

```php
class PublishNode extends Node
{
    public function __invoke(DraftReady $event, WorkflowState $state): Published
    {
        $answer = $this->interrupt(new ApprovalRequest('Publish the draft?', [
            new Action('publish', 'Publish', 'Publish the reviewed draft'),
        ]));
        $this->audit->record($answer);

        return new Published();
    }
}
```

### Case 2: Middleware reading tool approval decisions

Remove it and follow guide 11: approval is configured on the tool, and `ToolNode` applies the decisions.

## Verification Checklist

- [ ] The search pattern matches only `$this->isResuming()` inside classes that extend `Node`
- [ ] No middleware reads resume state from the node it wraps
