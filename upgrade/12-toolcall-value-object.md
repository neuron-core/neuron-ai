# Upgrade: Tool calls travel as ToolCall value objects

## What Changed

Messages no longer carry executable `Tool` objects. A new value object,
`NeuronAI\Tools\ToolCall`, is the conversation-side record of a tool invocation — name,
callId, inputs, description, result, and approval state. The `Tool` you register on the
agent is pure capability and never travels; `ToolNode` resolves each call against the
live registry at execution time.

1. **`getTools()` is renamed `getToolCalls()` and returns `ToolCall[]`** on
   `ToolCallMessage` and `ToolResultMessage` (a message holds *calls*, never tools;
   `Agent::getTools()` — the registry getter — is unchanged). The entry accessors
   (`getName()`, `getCallId()`, `getInputs()`, `getResult()`, `jsonSerialize()`) are
   unchanged, so most read-side code migrates with the method rename alone; `hasResult()`
   and the approval-state accessors (item 3) are new. Anything calling `execute()`,
   `getProperties()`, or other capability methods on a message entry must stop — that is
   the live registry's job.
2. **Data contexts build `ToolCall`; execution contexts extend `Tool`.** In 3.x one
   concrete `Tool` was both the executable and the message entry (`setCallId()`,
   `setInputs()`, `setResult()` on the tool itself). Message construction and history
   fixtures now build `ToolCall`, honestly typed as data; only executable tools extend
   `Tool` (abstract since guide 1).
3. **Approval state lives on the call.** `getApprovalState()`, `getApprovalReason()` and
   `getRejectReason()` read the state stamped on the entry (guide 5); `setApprovalState()`
   and `setApprovalReason()` stamp it when a fixture needs to. `ToolInterface` carries no
   approval state: the policy side stays on the tool — `requiresApproval()`,
   `approvalPolicy()`, `requireApproval()`, `suppressApproval()`, `withApprovalPolicy()`
   (see upgrade 11).
4. **Nothing serializes a `Tool` anymore.** Tools never travel inside messages or
   persisted state, so a tool holding a closure, a PDO connection or an HTTP client works
   with every persistence backend with no special handling.
5. **Stream chunks and observability events carry `ToolCall`.**
   `ToolCallChunk::$tool`, `ToolResultChunk::$tool`, `ToolCalling::$tool`, and
   `ToolCalled::$tool` are typed `ToolCall`. Listener code reading
   name/inputs/result/`jsonSerialize()` is unaffected.
6. **The tool error handler receives the call.** `toolErrorHandler(fn (Throwable $e,
   ToolCall $call): ?string)` — same behavior, new type on the second parameter.
7. **A call naming a tool outside the cycle's offering now throws** a clear
   `ToolException` at execution (routed through the error handler if set), instead of
   silently executing a dependency-free shell after a failed rehydration. Resolution reads
   the inference event's tool list only — `ToolNode` no longer takes a tool registry in
   its constructor.
8. **The workflow contract is the application contract only.** `getNodeForEvent()`,
   `getEventNodeMap()` and `getMiddlewareForNode()` left `WorkflowInterface` and
   `Workflow`: each execution segment builds its own graph internally. `getStartEvent()`
   and `getEventDispatcher()` stay public on `Workflow`. Code that read the graph of a
   workflow can render it with `export()`.

Unchanged: the on-disk chat history format (stored tool entries deserialize into
`ToolCall` transparently; the schema-side `parameters` key is ignored on read and no
longer written), the resume payload shape, runId adoption, and the approval flow.

## What to Search For

```
grep -rn "new ToolCallMessage(\|new ToolResultMessage(" --include="*.php" .
grep -rn "->getTools()\|->getToolCalls()" --include="*.php" .
```

## How to Refactor

### Case 1: Building messages by hand (tests, fixtures, custom history tooling)

Before (the entry was a tool object — a registry tool cloned and stamped, or a throwaway
`Tool::make()`):

```php
$message = new ToolCallMessage(null, [
    (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'php']),
]);
```

After:

```php
use NeuronAI\Tools\ToolCall;

$message = new ToolCallMessage(null, [
    ToolCall::make('search', 'call_1', ['query' => 'php']),
]);
```

`ToolCall::make(name, callId, inputs, description)`; `setResult()` chains as it did on the old
tool entries, and `setApprovalState()` / `setApprovalReason()` stamp approval state on a fixture.

### Case 2: Reading tool entries from messages (UIs, exporters, analytics)

The accessors you already used are unchanged; result presence and approval state are new on
the entry:

```php
foreach ($message->getToolCalls() as $call) {
    $call->getName();
    $call->getInputs();
    $call->hasResult() ? $call->getResult() : null;
    $call->getApprovalState();   // null when the tool was not gated
    $call->getApprovalReason();  // why it asked (outbound)
    $call->getRejectReason();    // the approver's feedback (inbound)
}
```

### Case 3: Custom code that serialized tools

Delete it. A `ToolCall` serializes natively (`serialize()`/`unserialize()`, or
`jsonSerialize()` for the wire shape); a `Tool` must never be serialized — if you were
persisting tools to reconstruct calls later, persist the `ToolCall` entries instead.

## Verification Checklist

- [ ] Hand-built `ToolCallMessage` / `ToolResultMessage` entries are `ToolCall` objects, never tools
- [ ] Nothing calls `execute()` or schema methods on entries read from messages
- [ ] Approval state is read from `ToolCall` entries (`getApprovalState()`, `getApprovalReason()`,
      `getRejectReason()`), never from registry tools
- [ ] Custom `toolErrorHandler` callbacks type the second parameter as `ToolCall`
- [ ] Custom stream/observability listeners still work (they should, via shared accessors)
- [ ] Tools with unserializable dependencies run under durable persistence with no wrappers
