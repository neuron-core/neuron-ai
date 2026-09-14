# Upgrade: ToolApproval middleware removed — approval is Tool-centric and owned by ToolNode

## What Changed

The `NeuronAI\Agent\Middleware\ToolApproval` middleware no longer exists. Tool
approval is now owned by `ToolNode` itself, and its configuration is **Tool-centric**: the
policy and its overrides live on the tool instance, not in a middleware config map.

1. **`addMiddleware(ToolNode::class, new ToolApproval(...))` is removed.** There is nothing to
   attach: the gate runs on every tool call and asks each tool whether it requires approval.
2. **Config-map entries become per-tool overrides at attach time.** Fluent helpers on `Tool`:
   `requireApproval(bool $require = true)`, `suppressApproval()`, `withApprovalPolicy(callable)`.
   A direct `ToolInterface` implementor expresses the same answer in its own `requiresApproval()`.
3. **Conditional callbacks receive the tool, not its inputs.** A `fn (array $inputs): bool`
   config entry becomes a `withApprovalPolicy()` callback with a `ToolInterface $tool`
   parameter. It reads the inputs through the tool, and may return a string: that counts as
   `true` and is the reason shown to the approver.
4. **An empty config no longer gates every tool.** `new ToolApproval()` required approval for
   all tools. Now a tool is gated only when its own `approvalPolicy()` (guide 5) answers `true`
   or it is attached with `requireApproval()`.

Unchanged: the resume payload shape (`['<callId>' => 'approve' | 'reject' | ['reject', $reason]]`),
the incremental-decision contract (silence is never consent, incomplete sets re-suspend), the
runId stamping/adoption via chat history, the append-only history behavior, and reading pending
approvals from the thread tail.

## What to Search For

```
grep -rn "ToolApproval" --include="*.php" .
```

Matches come from `addMiddleware()` calls and from `middleware()` hooks. Follow each one into
its config array: string entries and callback entries migrate differently (cases 2 and 3).

## How to Refactor

### Case 1: Zero-config attachment (every tool required approval)

Before:

```php
$agent->addMiddleware(ToolNode::class, new ToolApproval());
```

After — delete the line (and the `ToolApproval` / `ToolNode` imports it needed). The empty
config gated every tool, so keep that behavior explicitly by requiring approval on each tool
where you attach it (or declare `approvalPolicy()` once in a base class your tools extend):

```php
protected function tools(): array
{
    return [
        DeleteFile::make()->requireApproval(),
        SendEmail::make()->requireApproval(),
    ];
}
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

Before (the callback received the raw input array):

```php
$agent->addMiddleware(ToolNode::class, new ToolApproval([
    MoneyTransfer::class => fn (array $inputs): bool => ($inputs['amount'] ?? 0) > 100,
]));
```

After — install the callback on the tool. It receives the `ToolInterface` instance: read the
inputs with `$tool->getInputs()` or `$tool->getInput('amount')`. Returning `bool` still works;
returning a string counts as `true` and is shown to the approver as the reason:

```php
use NeuronAI\Tools\ToolInterface;

MoneyTransfer::make()->withApprovalPolicy(
    fn (ToolInterface $tool): bool|string => ($tool->getInputs()['amount'] ?? 0) > 100
        ? 'Transfers above $100 require a human sign-off'
        : false
);
```

### Case 4: Waiving a declared policy

A callback that returned `false` only skipped the gate for that call, and a tool the config did
not name was never gated, so such entries are simply dropped. What needs waiving now is a tool
that declares its own risk in `approvalPolicy()` (guide 5): it is gated with nothing attached,
so waive it where you attach it:

```php
DeleteFile::make()->suppressApproval();
```

The last configured override wins: `suppressApproval()` clears an earlier
`withApprovalPolicy()` callback and vice versa. The failure mode without action is a
fail-safe pause (the agent asks first), not a crash.

## Resume endpoints are unchanged

```php
$agent = Agent::make()
    ->setChatHistory(new SQLChatHistory($threadId, $pdo))
    ->setPersistence($persistence);

// The run is identified by the thread alone, as before. (The mechanism behind
// that changes in guide 14: the thread itself becomes the run's workflow ID.)
$agent->chat(payload: ['call_123' => 'approve', 'call_456' => ['reject', 'too expensive']]);
```

## Verification Checklist

- [ ] No `use NeuronAI\Agent\Middleware\ToolApproval;` imports remain
- [ ] No `addMiddleware(ToolNode::class, ...)` registrations or `middleware()` hook entries for
      approval remain
- [ ] Every former config-map entry is expressed on the tool instance
      (`requireApproval()` / `suppressApproval()` / `withApprovalPolicy()`)
- [ ] Every former conditional callback takes `ToolInterface $tool` and reads inputs through it
- [ ] Tools that were gated only by an empty `new ToolApproval()` are now `requireApproval()`-ed
- [ ] Tools that declare risk and must NOT gate are explicitly `suppressApproval()`-ed
- [ ] The approval test-path of your app still suspends and resumes with the same payloads
