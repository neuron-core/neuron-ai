# Upgrade: Tool approval remodel — chat history as system of record

## Summary

Tool approval was reworked so that **chat history is the system of record** for approval
state (ADR 0003) and **tools declare their own approval default** (ADR 0004). This is a
breaking change to four areas:

1. **`ToolInterface` gained four methods** — direct implementors must add them.
2. **`ToolApproval` empty-config semantics changed** — `new ToolApproval()` now means
   "each tool decides" (was: "all tools require approval").
3. **Resume payloads are incremental** — they carry only NEW decisions, not the full set.
4. **`ApprovalRequest`/`Action` lost their round-trip mutators** — `fromArray()`,
   `generatePayload()`, and the `Action` mutators are removed.
5. **A new turn throws `AgentException`** on a thread whose last message still has pending
   approvals (the application must keep the thread locked until decisions are delivered).

## 1. `ToolInterface` gained four methods

If a class `implements ToolInterface` directly (instead of extending `Tool`), add:

```php
public function requiresApproval(array $inputs): bool { return false; }
public function getApprovalState(): ?ApprovalState { return null; }
public function setApprovalState(ApprovalState $state, ?string $reason = null): ToolInterface { return $this; }
public function getApprovalReason(): ?string { return null; }
```

Anything extending `Tool` (including `ToolDefinition` and every built-in toolkit tool) is
covered automatically.

## 2. `new ToolApproval()` now means "each tool decides"

A tool may override `requiresApproval(array $inputs): bool` (default `false`) to declare
intrinsic risk:

```php
class TransferMoneyTool extends Tool
{
    public function requiresApproval(array $inputs): bool
    {
        return ($inputs['amount'] ?? 0) > 100;
    }
}
```

The declaration does nothing until the `ToolApproval` middleware is attached. Middleware
config overrides it in **both** directions:

```php
new ToolApproval([                              // empty = each tool decides (NEW default)
    DeleteFile::class,                          // force approval, even if it declares false
    'transfer_money' => fn (Tool $t): bool => false,  // waive a tool that declares true
]);
```

**Migration:** if you relied on `new ToolApproval()` meaning "approve ALL tools", switch to
listing the tools explicitly, or have each tool declare `requiresApproval() => true`.

## 3. Resume payloads are incremental

The payload now carries **only new decisions**, keyed by the tool callId. Prior decisions
are no longer re-stated — they persist in chat history.

```php
// Deliver one decision at a time — supported natively.
$agent->chat(payload: ['call_123' => 'approve']);
$agent->chat(payload: ['call_456' => ['reject', 'too expensive']]);
```

A tool runs **iff** explicitly approved; silence is never consent. Decisions are revisable
(last-write-wins) until the full set is complete.

**Migration:** stop accumulating the full decision set client-side. Send each decision as it
arrives. Remove any use of `ApprovalRequest::generatePayload()`.

## 4. `ApprovalRequest` and `Action` are outbound-only

These methods are removed:

- `ApprovalRequest::fromArray()`
- `ApprovalRequest::generatePayload()`
- `Action::fromArray()`
- `Action::approve()`, `Action::reject()`, `Action::decision()`, `Action::feedback()`

`ApprovalRequest` is a pure outbound snapshot the caller renders; `Action` is a readonly
value object. Decisions travel inbound as the resume payload (section 3).

**Migration:** build payload arrays directly from the rendered UI instead of mutating the
request or its actions.

## 5. New-turn guard

Starting a fresh `chat()` / `stream()` / `structured()` turn (payload `null`) on a thread
whose last message still has **pending** tool approvals now throws `AgentException`. Keep
the UI locked until every decision is delivered; resume the workflow to resolve pendings.
Stale suspensions are handled by the existing deadline machinery (`expiresAt` /
`$timedOut`).

## What to search for

```
grep -rn "generatePayload\|fromArray\|->approve(\|->reject(" --include="*.php" .
grep -rn "implements ToolInterface" --include="*.php" .
```

## Dependencies this requires

- **Workflow persistence** — attach a persistence backend (the suspend/resume machinery).
- **A durable chat history** — `FileChatHistory`, `SQLChatHistory`, or `EloquentChatHistory`.
  `InMemoryChatHistory` preserves the safety property (undecided tools re-suspend) but loses
  recorded progress across processes.
