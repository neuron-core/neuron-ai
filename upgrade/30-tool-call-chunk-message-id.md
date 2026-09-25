# Upgrade: `ToolCallChunk` carries its message ID, and AG-UI IDs follow storage

## Summary

`ToolCallChunk` now carries the ID of the `ToolCallMessage` holding the call, as every other chunk carries
the ID of its message (guide 29). The constructor takes it first.

| Before | After |
|---|---|
| `new ToolCallChunk(ToolCall $tool)` | `new ToolCallChunk(string $messageId, ToolCall $tool)` |
| `ToolNode::executeLocalTools(array $calls)` (protected, also on `ParallelToolNode`) | `ToolNode::executeLocalTools(array $calls, string $messageId)` |
| `AGUIAdapter::resolveToolCallId(ToolCallChunk\|ToolResultChunk $chunk)` (protected) | `AGUIAdapter::resolveToolCallId(ToolCall $call)` |

The AG-UI adapter uses it. Tool calls publish under the ID of the stored `ToolCallMessage`, including calls
published after an approval or when a run suspends for frontend results, and a tool result's message ID is
`result_{callId}` instead of a random `msg_…`. Every ID an AG-UI client receives from Neuron is now a stored
message ID or derived from one, so `AGUIAdapter::hydrate()` rebuilds a reloaded page with the IDs it rendered.

## How to Refactor

### Case 1: Code constructing `ToolCallChunk`

Pass the ID of the message holding the call. A node handling a `ToolCallEvent` has that message.

Before:

```php
yield new ToolCallChunk($call);
```

After:

```php
yield new ToolCallChunk($event->toolCallMessage->getId(), $call);
```

### Case 2: A node overriding `executeLocalTools()`

Add the parameter and pass it to the chunks and to the parent.

Before:

```php
protected function executeLocalTools(array $calls): Generator
{
    $this->audit($calls);

    return yield from parent::executeLocalTools($calls);
}
```

After:

```php
protected function executeLocalTools(array $calls, string $messageId): Generator
{
    $this->audit($calls);

    return yield from parent::executeLocalTools($calls, $messageId);
}
```

### Case 3: An `AGUIAdapter` subclass

An override of `resolveToolCallId()` receives the `ToolCall` itself. Replace `$chunk->tool` with `$call`.

### Case 4: A frontend relying on AG-UI message IDs

A `TOOL_CALL_RESULT` message is `result_{callId}`, and the assistant message holding a call is the stored
`ToolCallMessage` ID rather than a random ID. Frontend code that keyed anything on the old random IDs must use
the IDs it receives now; they no longer change between a live stream and a reload.

### Case 5: Hand-built AG-UI reload payloads

An application that rebuilt the AG-UI message list and interrupts itself after a page reload can serve
`AGUIAdapter::hydrate()` instead. It returns what the client held when the live stream ended, with the
stored IDs, the same interrupt payloads, and the rules for calls still in progress.

Before:

```php
$messages = $agent->getChatHistory()->getMessages();
$approvals = $this->toAGUIInterrupts($agent->pendingApprovals());

return [
    'messages' => $this->toAGUIMessages($approvals === [] ? $messages : array_slice($messages, 0, -1)),
    'pendingApprovals' => $approvals,
];
```

After:

```php
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Workflow\WorkflowEngine;

$run = (new WorkflowEngine($persistence))->inspect($threadId);
$page = (new AGUIAdapter($threadId))->hydrate($messageStore->loadAll($threadId, limit: 50), $run);

return ['messages' => $page['messages'], 'pendingApprovals' => $page['interrupts']];
```

Seed the client with `messages` as its initial messages and `interrupts` as its pending interrupts. Pass the
run only with the latest page; older pages take `null`.

## What to Search For

```
grep -rn "new ToolCallChunk(" --include="*.php" .
grep -rn "function executeLocalTools(" --include="*.php" .
grep -rn "resolveToolCallId(" --include="*.php" .
grep -rn "pendingApprovals()\|getChatHistory()->getMessages()" --include="*.php" .
```

## Checklist

- Every `ToolCallChunk` is constructed with the ID of the message holding the call.
- Overrides of `executeLocalTools()` declare and forward `string $messageId`.
- Overrides of `AGUIAdapter::resolveToolCallId()` take a `ToolCall`.
- No frontend depends on random AG-UI tool result or parent message IDs.
