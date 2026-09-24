# Upgrade: Approval types moved to `Agent\Interrupt`

## Summary

In 3.x the approval types lived under `NeuronAI\Workflow\Interrupt`. They describe tool calls awaiting a human
decision, which is an Agent concept, so they moved to `NeuronAI\Agent\Interrupt`. The Workflow keeps only the
protocol-neutral interrupt types (`InterruptRequest`, `WaitForEventRequest`, `SleepUntilRequest`).

| 3.x class | 4.x class |
|---|---|
| `NeuronAI\Workflow\Interrupt\ApprovalRequest` | `NeuronAI\Agent\Interrupt\ApprovalRequest` |
| `NeuronAI\Workflow\Interrupt\Action` | `NeuronAI\Agent\Interrupt\Action` |
| `NeuronAI\Workflow\Interrupt\ActionDecision` | `NeuronAI\Agent\Interrupt\ActionDecision` |

Class names are unchanged; only the namespace differs. There is no alias, so a reference to the old namespace is
a fatal "class not found" error at runtime.

## What to Search For

Search the whole application, including tests and config, excluding `vendor/`:

```
grep -rnE "Workflow\\\\Interrupt\\\\(ApprovalRequest|Action)" --include="*.php" .
grep -rnE "Workflow\\\\Interrupt\\\\(ApprovalRequest|Action)" --include="*.yaml" --include="*.yml" --include="*.neon" --include="*.xml" --include="*.json" .
```

The pattern `Action` also matches `ActionDecision`. The second command finds class names written as strings in
container definitions and static analysis configuration.

## How to Refactor

Rewrite every reference according to the table above. Nothing else in the file changes.

Before:

```php
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
```

After:

```php
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ActionDecision;
```

Fully-qualified references and `::class` constants change the same way.

## Verification Checklist

- [ ] The search patterns above return no matches outside `vendor/`
- [ ] `composer dump-autoload` and the test suite (or `vendor/bin/phpstan`) pass
