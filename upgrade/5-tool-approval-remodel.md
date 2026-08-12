# Upgrade: Tool approval remodel — chat history as system of record

> **Forward note:** guide 11 removes the `ToolApproval` middleware entirely (approval becomes
> Tool-centric, owned by `ToolNode`) and renames the subclass hook `requiresApproval()` →
> `approvalPolicy()`. The concepts this guide introduces (self-declaration, approval state in
> chat history, string reasons) all survive — only the middleware attachment does not. If you
> are upgrading in one sitting, apply sections 1 and 3–5 here and fold section 2 into step 11.

## Summary

Tool approval was reworked so that **chat history is the system of record** for approval
state and **tools declare their own approval default**. This is a
breaking change to four areas:

1. **`ToolInterface` gained six approval methods** — direct implementors must add them.
2. **`ToolApproval` empty-config semantics changed** — `new ToolApproval()` now means
   "each tool decides" (was: "all tools require approval").
3. **Resume payloads are cumulative** — every resume restates the entire decision set
   (an earlier incremental contract never shipped).
4. **`ApprovalRequest`/`Action` lost their round-trip mutators** — `fromArray()`,
   `generatePayload()`, and the `Action` mutators are removed.
5. **A new user turn on a thread with a pending tool call is rejected** at the chat history
   level (the application must keep the thread locked until decisions are delivered).

## 1. `ToolInterface` gained six approval methods

If a class `implements ToolInterface` directly (instead of extending `Tool`), add:

```php
public function requiresApproval(array $inputs): bool|string { return false; }
public function getApprovalReason(): ?string { return null; }
public function setApprovalReason(?string $reason): ToolInterface { return $this; }
public function getApprovalState(): ?ApprovalState { return null; }
public function setApprovalState(ApprovalState $state, ?string $reason = null): ToolInterface { return $this; }
public function getRejectReason(): ?string { return null; }
```

Two distinct reasons exist — don't conflate them:

- **`approvalReason`** (outbound): why the tool is *asking* for approval — declared by the
  tool or the middleware config, shown to the approver.
- **`rejectReason`** (inbound): the approver's feedback recorded with a rejection — set via
  `setApprovalState(ApprovalState::Rejected, $reason)`.

Anything extending `Tool` (including `ToolDefinition` and every built-in toolkit tool) is
covered automatically.

## 2. `new ToolApproval()` now means "each tool decides"

A tool may override `requiresApproval(array $inputs): bool|string` (default `false`) to
declare intrinsic risk. Returning a **string counts as `true`** and doubles as the approval
reason shown to the approver (persisted on the tool entry in chat history as
`approvalReason`):

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

The declaration does nothing until the `ToolApproval` middleware is attached. Middleware
config overrides it in **both** directions, with the same `bool|string` semantics:

```php
new ToolApproval([                              // empty = each tool decides (NEW default)
    DeleteFile::class,                          // force approval, even if it declares false
    'transfer_money' => fn (Tool $t): bool|string => false,  // waive a tool that declares true
]);
```

**Migration:** if you relied on `new ToolApproval()` meaning "approve ALL tools", switch to
listing the tools explicitly, or have each tool declare `requiresApproval() => true`.

## 3. Resume payloads are cumulative

The payload is the **entire decision set**, keyed by the tool callId, restated on every
resume. Accumulation lives with the caller: gather decisions app-side and resume
with the full set. An incomplete set re-suspends; undelivered partial decisions are
deliberately persisted nowhere.

```php
// The full decision set in one resume.
$agent->chat(payload: [
    'call_123' => 'approve',
    'call_456' => ['reject', 'too expensive'],
]);
```

A tool runs **iff** explicitly approved; silence is never consent. Decisions are revisable
(the latest delivered payload wins) until the set completes.

**Migration:** accumulate the decision set client-side and send it whole. Remove any use of
`ApprovalRequest::generatePayload()`.

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

## 5. New-turn rejection

Thread integrity during a suspension is the application's responsibility: keep the UI locked
until every decision is delivered. If a new user turn slips through anyway, the chat
history's message-alternation rule rejects it — appending a `UserMessage` directly after a
`ToolCallMessage` throws `ChatHistoryException` (a tool call must be answered by a
`ToolResultMessage` first). Stale suspensions are handled by the existing deadline machinery
(`expiresAt` / `$timedOut`).

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
