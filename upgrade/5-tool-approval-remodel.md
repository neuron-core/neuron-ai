# Upgrade: Tool approval remodel — chat history as system of record

## Summary

Tool approval was reworked so that **chat history is the system of record** for approval
state and **tools declare their own approval default**. This is a
breaking change to four areas:

1. **`ToolInterface` gained `requiresApproval()`** — direct implementors must add it.
2. **Tools declare their own approval default** — a `Tool` subclass overrides
   `approvalPolicy()`; a string return doubles as the approval reason.
3. **Approval submissions are incremental** — ToolNode durably preserves earlier decisions.
4. **`ApprovalRequest`/`Action` lost their round-trip mutators** — `fromArray()`,
   `generatePayload()`, and the `Action` mutators are removed.
5. **A new user turn on a thread with a pending tool call is rejected** at the chat history
   level (the application must keep the thread locked until decisions are delivered).

## 1. `ToolInterface` gained `requiresApproval()`

If a class `implements ToolInterface` directly (instead of extending `Tool`), add:

```php
public function requiresApproval(): bool|string { return false; }
```

Two distinct reasons exist on the tool entry in chat history — don't conflate them:

- **`approvalReason`** (outbound): why the tool is *asking* for approval — declared by the
  tool's approval policy, shown to the approver.
- **`rejectReason`** (inbound): the approver's feedback delivered with a rejection (section 3),
  recorded on the entry and shown to the model.

Anything extending `Tool`, including every built-in toolkit tool, is covered automatically.

## 2. Tools declare their own approval default

A `Tool` subclass declares its intrinsic risk by overriding the protected `approvalPolicy()`
hook (default `false`). Returning a **string counts as `true`** and doubles as the approval
reason shown to the approver (persisted on the tool entry in chat history as
`approvalReason`). A direct `ToolInterface` implementor answers through `requiresApproval()`
itself (section 1):

```php
class TransferMoneyTool extends Tool
{
    protected function approvalPolicy(): bool|string
    {
        return ($this->inputs['amount'] ?? 0) > 100
            ? 'Transfers above $100 require a human sign-off'
            : false;
    }
}
```

## 3. Approval submissions are incremental

The payload contains decisions keyed by tool call ID. ToolNode durably accumulates
delivered decisions, so each `submitApprovalDecisions($decisions)` call may contain
only newly decided actions. Finish with `run()` or `events()`; an incomplete set
re-suspends. Application code does not construct a translator or name an event.

```php
// Submit decisions keyed by tool call ID, then continue.
$agent->submitApprovalDecisions([
    'call_123' => 'approve',
    'call_456' => ['reject', 'too expensive'],
])->run();
```

A tool runs **iff** explicitly approved; silence is never consent. Decisions are revisable
(the latest delivered payload wins) until the set completes.

**Migration:** use `submitApprovalDecisions($decisions)->run()` or `->events()`; client-side accumulation is optional. Remove any use of
`ApprovalRequest::generatePayload()`.

For deferred/frontend tools, approval authorizes execution. After execution,
continue with `submitToolResults(['call_123' => ['result' => $value]])->run()`
or `->events()`. Use `['error' => 'Execution failed']` for an error outcome.
Tool results are separate from approval decisions.

## 4. `ApprovalRequest` and `Action` are outbound-only

These methods are removed:

- `ApprovalRequest::fromArray()`
- `ApprovalRequest::generatePayload()`
- `Action::fromArray()`
- `Action::approve()`, `Action::reject()`, `Action::decision()`, `Action::feedback()`

`ApprovalRequest` is a pure outbound snapshot the caller renders; `Action` is a readonly
value object. Decisions travel inbound through `submitApprovalDecisions()` (section 3).

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
