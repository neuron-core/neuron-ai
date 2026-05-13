# Upgrade: ToolApproval Callback Receives ToolInterface

## Summary

The `ToolApproval` middleware callback signature changed. Previously it received `array $inputs` — the raw input parameters. Now it receives the full `ToolInterface` instance, giving access to tool name, properties, inputs, and all other tool state.

## What to Search For

Search for `ToolApproval` usage across the application:

```
grep -rn "ToolApproval" --include="*.php" .
```

Within matching files, look for callback patterns in the constructor array:

```
grep -rn "function (array" --include="*.php" .
grep -rn "fn (array" --include="*.php" .
grep -rn "fn(array" --include="*.php" .
```

Specifically target callbacks inside `ToolApproval` constructor calls — these are the ones that changed signature.

## Refactoring Instructions

### Case 1: Callback accessing inputs by key

The most common pattern — the callback inspected `$inputs['some_key']` to make an approval decision.

**Before:**

```php
new ToolApproval(
    tools: [
        TransferMoneyTool::class => function (array $inputs): bool {
            return ($inputs['amount'] ?? 0) > 100;
        },
    ]
);
```

**After — change the parameter type to `ToolInterface` and access inputs via `$tool->getInputs()`:**

```php
use NeuronAI\Tools\ToolInterface;

new ToolApproval(
    tools: [
        TransferMoneyTool::class => function (ToolInterface $tool): bool {
            return ($tool->getInputs()['amount'] ?? 0) > 100;
        },
    ]
);
```

Or use the convenience `$tool->getInput('amount')` method:

```php
new ToolApproval(
    tools: [
        TransferMoneyTool::class => function (ToolInterface $tool): bool {
            return ($tool->getInput('amount') ?? 0) > 100;
        },
    ]
);
```

### Case 2: Callback using `fn()` arrow syntax

**Before:**

```php
new ToolApproval(
    tools: [
        DeleteFileTool::class => fn (array $inputs): bool => true,
    ]
);
```

**After:**

```php
use NeuronAI\Tools\ToolInterface;

new ToolApproval(
    tools: [
        DeleteFileTool::class => fn (ToolInterface $tool): bool => true,
    ]
);
```

### Case 3: Callback that only checked input existence

**Before:**

```php
new ToolApproval(
    tools: [
        SendEmailTool::class => function (array $inputs): bool {
            return isset($inputs['recipient']);
        },
    ]
);
```

**After:**

```php
use NeuronAI\Tools\ToolInterface;

new ToolApproval(
    tools: [
        SendEmailTool::class => function (ToolInterface $tool): bool {
            return $tool->getInput('recipient') !== null;
        },
    ]
);
```

### Case 4: No callback — unconditional tool approval

If the `ToolApproval` configuration only uses string values (no callables), no change is needed:

```php
// No change required — this pattern is unaffected
new ToolApproval(
    tools: [
        DeleteFileTool::class,
        'send_email',
    ]
);
```

### Case 5: Empty constructor — approve all tools

```php
// No change required
new ToolApproval();
```

## Available Methods on ToolInterface

When refactoring the callback, the following `ToolInterface` methods are available:

| Method | Return | Purpose |
|--------|--------|---------|
| `getName()` | `string` | Tool identifier |
| `getDescription()` | `?string` | Tool description |
| `getInputs()` | `array` | All runtime input parameters |
| `getInput(string $key)` | `mixed` | Single input by key |
| `getProperties()` | `array` | Tool parameter schema |
| `getCallId()` | `?string` | LLM-generated call identifier |
| `getResult()` | `string` | Execution result |
| `getMaxRuns()` | `?int` | Max executions per session |

## Checklist

For each file you modify:

- [ ] Every callable in the `ToolApproval` constructor has `ToolInterface $tool` as the parameter type instead of `array $inputs`
- [ ] All `$inputs['key']` references are replaced with `$tool->getInputs()['key']` or `$tool->getInput('key')`
- [ ] The `NeuronAI\Tools\ToolInterface` import is added where needed
- [ ] The callback still returns `bool`
- [ ] Unconditional string entries and empty `ToolApproval()` calls are left unchanged
