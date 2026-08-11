# Upgrade: Tool calls travel as ToolCall value objects — ToolDefinition removed

## What Changed

Messages no longer carry executable `Tool` objects (ADR 0010). A new value object,
`NeuronAI\Tools\ToolCall`, is the conversation-side record of a tool invocation — name,
callId, inputs, description, result, and approval state. The `Tool` you register on the
agent is pure capability and never travels; `ToolNode` resolves each call against the
live registry at execution time.

1. **`getTools()` is renamed `getToolCalls()` and returns `ToolCall[]`** on
   `ToolCallMessage` and `ToolResultMessage` (a message holds *calls*, never tools;
   `Agent::getTools()` — the registry getter — is unchanged). The entry accessors
   (`getName()`, `getCallId()`, `getInputs()`, `getResult()`, `hasResult()`,
   `getApprovalState()`, `jsonSerialize()`) are unchanged, so most read-side code migrates
   with the method rename alone. Anything calling `execute()`, `getProperties()`, or other
   capability methods on a message entry must stop — that is the live registry's job.
2. **`ToolDefinition` is removed.** It existed as a concrete data-only stand-in for
   deserializing stored histories once `Tool` became abstract — a `Tool` impersonating
   data. `ToolCall` is that data, honestly typed. Data contexts (message construction,
   history fixtures) migrate to `ToolCall`; execution contexts extend `Tool` as usual.
3. **Approval-state accessors moved from tools to calls.** `getApprovalState()` /
   `setApprovalState()` / `getRejectReason()` / `getApprovalReason()` /
   `setApprovalReason()` are removed from `ToolInterface` and live on `ToolCall`. The
   policy side stays on the tool: `approvalPolicy()`, `requireApproval()`,
   `suppressApproval()`, `withApprovalPolicy()` (see upgrade 11).
4. **Tool serialization machinery is gone.** `Tool::__serialize()` / `__unserialize()` /
   `rehydrate()` / `isDehydrated()` no longer exist — nothing serializes a Tool anymore.
   Tools holding closures, PDO connections, or HTTP clients work with every persistence
   backend with no special handling.
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
8. **The workflow contract split in two (ADR 0011).** `WorkflowInterface` is now the
   application contract only; the engine-facing methods (`getStartEvent`, `getState`,
   `getNodeForEvent`, `getEventNodeMap`, `getMiddlewareForNode`, `getEventDispatcher`, and
   the new `restoreEventNode` — the seam where a workflow restores transient capability on
   events recalled from persisted steps) moved to `WorkflowRuntimeInterface`, which
   executors type against. `Workflow` implements both, so subclasses are unaffected; code
   that held a `WorkflowInterface` and called engine methods must hold the concrete
   workflow (or the runtime interface) instead.

Unchanged: the on-disk chat history format (stored tool entries deserialize into
`ToolCall` transparently; the schema-side `parameters` key is ignored on read and no
longer written), the resume payload shape, runId adoption, and the approval flow.

## What to Search For

```
grep -rn "ToolDefinition" --include="*.php" .
grep -rn "isDehydrated\|->rehydrate(" --include="*.php" .
grep -rn "getApprovalState\|setApprovalState\|getRejectReason\|getApprovalReason" --include="*.php" .
grep -rn "->getTools()\|->getToolCalls()" --include="*.php" .
```

## How to Refactor

### Case 1: Building messages by hand (tests, fixtures, custom history tooling)

Before:

```php
$tool = ToolDefinition::make('search', 'Search the web')
    ->setInputs(['query' => 'php'])
    ->setCallId('call_1');

$message = new ToolCallMessage(null, [$tool]);
```

After:

```php
use NeuronAI\Tools\ToolCall;

$message = new ToolCallMessage(null, [
    ToolCall::make('search', 'call_1', ['query' => 'php']),
]);
```

`ToolCall::make(name, callId, inputs, description)`; `setResult()`, `setApprovalState()`,
`setApprovalReason()` chain exactly as they did on the old tool entries.

### Case 2: Reading tool entries from messages (UIs, exporters, analytics)

No change for the documented read surface:

```php
foreach ($message->getToolCalls() as $call) {
    $call->getName();
    $call->getInputs();
    $call->hasResult() ? $call->getResult() : null;
    $call->getApprovalState();
}
```

### Case 3: Reading or stamping approval state on a tool object

Before (state on the tool):

```php
$tool->setApprovalState(ApprovalState::Rejected, 'too expensive');
$tool->getRejectReason();
```

After (state on the call — the entry inside the message):

```php
$call->setApprovalState(ApprovalState::Rejected, 'too expensive');
$call->getRejectReason();
```

### Case 4: `ToolDefinition` as a cheap concrete tool in tests

If you used it as a *registry* tool (something the agent executes), declare a small
concrete class or an anonymous one:

```php
$tool = new class () extends Tool {
    protected string $name = 'search';
    protected ?string $description = 'Search the web';

    public function __invoke(string $query): string
    {
        return '...';
    }
};
```

### Case 5: Custom code that serialized tools

Delete it. A `ToolCall` serializes natively (`serialize()`/`unserialize()`, or
`jsonSerialize()` for the wire shape); a `Tool` must never be serialized — if you were
persisting tools to reconstruct calls later, persist the `ToolCall` entries instead.

## Verification Checklist

- [ ] No references to `ToolDefinition`, `isDehydrated()`, or `rehydrate()` remain
- [ ] Nothing calls `execute()` or schema methods on entries read from messages
- [ ] Approval-state reads/writes target `ToolCall` entries, not registry tools
- [ ] Custom `toolErrorHandler` callbacks type the second parameter as `ToolCall`
- [ ] Custom stream/observability listeners still work (they should, via shared accessors)
- [ ] Tools with unserializable dependencies run under durable persistence with no wrappers
