# Upgrade: ToolApproval middleware removed — approval is Tool-centric and owned by ToolNode

## What Changed

The `NeuronAI\Agent\Middleware\ToolApproval` middleware no longer exists (ADR 0009). Tool
approval is now owned by `ToolNode` itself, and its configuration is **Tool-centric**: the
policy and its overrides live on the tool instance, not in a middleware config map.

1. **`addMiddleware(ToolNode::class, new ToolApproval(...))` is removed.** There is nothing to
   attach: the gate runs on every tool call and asks each tool whether it requires approval.
2. **Config-map entries become per-tool overrides at attach time.** Fluent helpers on `Tool`:
   `requireApproval(bool $require = true)`, `suppressApproval()`, `withApprovalPolicy(callable)`.
3. **The subclass hook renamed: `requiresApproval()` → protected `approvalPolicy()`.**
   `requiresApproval(array $inputs)` still exists on `ToolInterface`, but on the `Tool` base
   class it is now the *resolution point* (override → declaration) — subclasses declare their
   intrinsic risk in `approvalPolicy()` instead of overriding `requiresApproval()`.
4. **Declarations are live by default.** A tool whose policy answers `true` is gated even
   though no middleware was ever attached — the previous "declaration does nothing until
   `ToolApproval` is attached" no-op is gone. Waive per tool with `suppressApproval()`.
5. **`ToolCallMessage::isSameToolCall()` is removed.** It existed only for the middleware/node
   writer dedup, which is replaced by a single memoized write inside `ToolNode`.

Unchanged: the resume payload shape (`['<callId>' => 'approve' | 'reject' | ['reject', $reason]]`),
the cumulative-payload contract (silence is never consent, incomplete sets re-suspend), the
runId stamping/adoption via chat history, the append-only history behavior, and reading pending
approvals from the thread tail.

## What to Search For

```
grep -rn "ToolApproval" --include="*.php" .
grep -rn "isSameToolCall" --include="*.php" .
grep -rn "function requiresApproval" --include="*.php" .
```

## How to Refactor

### Case 1: Zero-config attachment ("each tool decides")

Before:

```php
$agent->addMiddleware(ToolNode::class, new ToolApproval());
```

After — delete the line (and the `ToolApproval` / `ToolNode` imports it needed). Tools that
declare a policy are gated automatically:

```php
// nothing to attach
```

### Case 2: Forcing approval on specific tools

Before:

```php
$agent->addMiddleware(ToolNode::class, new ToolApproval([
    DeleteFile::class,
    'transfer_money',
]));
```

After — set the flag on the instances where you attach them:

```php
protected function tools(): array
{
    return [
        DeleteFile::make()->requireApproval(),
        TransferMoney::make()->requireApproval(),
    ];
}
```

### Case 3: Conditional callback config

Before:

```php
$agent->addMiddleware(ToolNode::class, new ToolApproval([
    MoneyTransfer::class => fn (ToolInterface $tool) => ($tool->getInputs()['amount'] ?? 0) > 100
        ? 'Transfers above $100 require a human sign-off'
        : false,
]));
```

After — the same callback (identical signature and string-reason semantics), installed on the
tool:

```php
MoneyTransfer::make()->withApprovalPolicy(
    fn (ToolInterface $tool) => ($tool->getInputs()['amount'] ?? 0) > 100
        ? 'Transfers above $100 require a human sign-off'
        : false
);
```

### Case 4: Waiving a tool that declares its own risk

Before (a callable returning `false` waived the declaration):

```php
$agent->addMiddleware(ToolNode::class, new ToolApproval([
    DeleteFile::class => fn (ToolInterface $tool): bool => false,
]));
```

After:

```php
DeleteFile::make()->suppressApproval();
```

### Case 5: Tool subclasses declaring their own risk

Before:

```php
class TransferMoneyTool extends Tool
{
    public function requiresApproval(array $inputs): bool|string
    {
        return ($inputs['amount'] ?? 0) > 100
            ? 'Transfers above $100 require a human sign-off'
            : false;
    }
}
```

After — rename to the protected hook (same return semantics: a string counts as `true` and is
the approval reason shown to the approver):

```php
class TransferMoneyTool extends Tool
{
    protected function approvalPolicy(array $inputs): bool|string
    {
        return ($inputs['amount'] ?? 0) > 100
            ? 'Transfers above $100 require a human sign-off'
            : false;
    }
}
```

Direct `ToolInterface` implementors (not extending `Tool`) keep implementing
`requiresApproval(array $inputs): bool|string` — for them it IS the effective answer.

### Case 6: Tools that declared risk you were ignoring

Any tool whose `approvalPolicy()` (previously `requiresApproval()`) answers `true` now suspends
the agent even though you never attached the middleware. If that gating is unwanted, waive it
explicitly where you attach the tool:

```php
RiskyThirdPartyTool::make()->suppressApproval();
```

The failure mode without action is a fail-safe pause (the agent asks first), not a crash:
`chat()` returns a suspended handler resumable with the decision payload.

### Case 7: Direct callers of `isSameToolCall()`

The method is gone. If application code compared tool-call messages, compare the ordered
`getCallId()` lists of `getToolCalls()` yourself (renamed in upgrade 12). (Inside the framework the dedup it powered no
longer exists — `ToolNode` writes the message exactly once via a durable memo.)

## Resume endpoints are unchanged

```php
$agent = Agent::make()
    ->setChatHistory(new SQLChatHistory($threadId, $pdo))
    ->setPersistence($persistence);

// The runId is adopted from the thread's suspended tail (ADR 0005), as before.
$agent->chat(payload: ['call_123' => 'approve', 'call_456' => ['reject', 'too expensive']]);
```

## Verification Checklist

- [ ] No `use NeuronAI\Agent\Middleware\ToolApproval;` imports remain
- [ ] No `addMiddleware(ToolNode::class, ...)` registrations for approval remain
- [ ] Every former config-map entry is expressed on the tool instance
      (`requireApproval()` / `suppressApproval()` / `withApprovalPolicy()`)
- [ ] Every `Tool` subclass overriding `requiresApproval()` now overrides
      `protected function approvalPolicy(array $inputs): bool|string`
- [ ] Tools that declare risk and must NOT gate are explicitly `suppressApproval()`-ed
- [ ] No caller references `ToolCallMessage::isSameToolCall()`
- [ ] The approval test-path of your app still suspends and resumes with the same payloads
