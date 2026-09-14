# Upgrade: Agent returns AgentState — AgentHandler and wake() removed

## Summary

The Agent's public verbs now converge with the standard `Workflow` model. The
`AgentHandler` wrapper class is gone; `chat()`, `stream()`, `run()` and `events()` return
the type appropriate to their nature — the same eager/lazy split a plain Workflow uses
with `run()` (eager → state) and `events()` (lazy → generator):

| Method | Before | After |
|--------|--------|-------|
| `chat($messages)` | `AgentHandler` | `AgentState` (eager — runs to completion) |
| `stream($messages)` | `AgentHandler` | `Generator` (pull-stream; `getReturn()` is the `AgentState`) |
| `structured($messages, $class)` | `mixed` | `mixed` (unchanged) |
| `submitApprovalDecisions($decisions)->run()` | `wake($decisions)` returned `AgentHandler` | `AgentState` after approval continuation |
| `submitToolResults($results)->run()` | Generic continuation | `AgentState` after deferred tool results |
| `resume($payload)->run()` | Generic continuation | `AgentState` for other Workflow interruptions |
| `wake($payload)` | `AgentHandler` | **removed** — use the appropriate staging method and `run()` or `events()` |

`chat()` runs eagerly and returns the final `AgentState` directly — there is no longer a
separate `->run()` step. `stream()` *is* the generator: iterate it directly. Configure a
`StreamAdapterInterface` (Vercel / AG-UI / SSE) through `setStreamAdapter()` when you want
protocol-formatted lines instead of raw Neuron chunks.

For Agent tool responses, stage `submitApprovalDecisions($decisions)` or
`submitToolResults($results)`, then execute with `run()` or `events()`. Generic
Workflow interruptions use `resume($payload)->run()`. `wake()` no longer exists. Interrupt state is read on the returned `AgentState` itself, exactly like a plain
`WorkflowState`.

**Important:** This is the Agent-side counterpart of upgrade 2 (which removed
`WorkflowHandler`). Until now the Agent kept its own `AgentHandler` wrapper; this step
removes it so the Agent and Workflow share one execution model.

## What to Search For

```
grep -rn "AgentHandler" --include="*.php" .
grep -rn "\->wake(" --include="*.php" .
grep -rn "\->events(" --include="*.php" .
grep -rn "\->interrupted(" --include="*.php" .
grep -rn "\->getState(" --include="*.php" .
grep -rn "\->getProviderResponse(" --include="*.php" .
grep -rn "\->getResult(" --include="*.php" .
```

Then follow the call sites you find: any variable that received the result of
`$agent->chat()`, `$agent->stream()`, or `$agent->wake()` previously held an
`AgentHandler` and needs migrating.

## How to Refactor

### Method-name map

| Old (`AgentHandler`) | New (on `AgentState`, or the generator) |
|---|---|
| `$h = $agent->chat($m)` | `$state = $agent->chat($m)` (`AgentState`) |
| `$h->run()` | *(removed — `chat()` already runs to completion)* |
| `$h->getMessage()` | `$state->getMessage()` |
| `$h->getProviderResponse()` | `$state->getResponse()` |
| `$h->getState()` | `$state` (it already *is* the state) |
| `$h->interrupted()` | `$state->isInterrupted()` |
| `$h->getInterruptRequest()` | `$state->getInterruptRequest()` |
| `$agent->wake($decisions)` for approval | `$agent->submitApprovalDecisions($decisions)->run()` (`AgentState`) |
| `$agent->wake($results)` for deferred tools | `$agent->submitToolResults($results)->run()` (`AgentState`) |
| `$agent->wake($payload)` for another interruption | `$agent->resume($payload)->run()` (`AgentState`) |

### Case 1: Chat — one-shot, then read the message

Before:

```php
$handler = $agent->chat(new UserMessage('Hello'));
$response = $handler->run()->getMessage();
echo $response->getContent();
```

After — `chat()` is eager; it returns the final state, and `getMessage()` reads the
assistant message off the stored provider response:

```php
$state = $agent->chat(new UserMessage('Hello'));
echo $state->getMessage()->getContent();
```

> The chained shape `$agent->chat($m)->getMessage()` keeps working unchanged — `chat()`
> now returns the state that owns `getMessage()`.

### Case 2: Streaming raw chunks

Before:

```php
$handler = $agent->stream(new UserMessage('Hello'));
foreach ($handler->events() as $chunk) {
    if ($chunk instanceof TextChunk) {
        echo $chunk->content;
    }
}
$state = $handler->getResult();
```

After — `stream()` is itself the generator; consume it directly:

```php
foreach ($agent->stream(new UserMessage('Hello')) as $chunk) {
    if ($chunk instanceof TextChunk) {
        echo $chunk->content;
    }
}

// Optional: the final state is the generator's return value.
$generator = $agent->stream(new UserMessage('Hello'));
foreach ($generator as $chunk) { /* ... */ }
$state = $generator->getReturn();
```

### Case 3: Streaming through a UI adapter (Vercel / AG-UI / SSE)

Before — the adapter was passed to `events()` on the handler:

```php
$handler = $agent->stream(new UserMessage($input));
foreach ($handler->events(new VercelAIAdapter()) as $line) {
    echo $line;
}
```

After — configure the Workflow component, then stream normally:

```php
$agent->setStreamAdapter(new VercelAIAdapter());

foreach ($agent->stream(new UserMessage($input)) as $line) {
    echo $line;
}
```

When a live channel is also attached, Workflow delivers these same adapted
protocol events to its `send()` port. The adapter and channel compose
independently.

### Case 4: Continuing a suspended run (tool approval, etc.)

Before — `wake()` returned a handler you drove to completion:

```php
$handler = $agent->wake(['call_123' => 'approve']);
$message = $handler->run()->getMessage();
```

After — stage approval decisions, then execute with `run()` to get `AgentState`:

```php
$state = $agent->submitApprovalDecisions(['call_123' => 'approve'])->run();
echo $state->getMessage()->getContent();
```

For deferred tool results, use the matching method after external execution:

```php
$state = $agent->submitToolResults([
    'call_123' => ['result' => ['title' => 'Example']],
    'call_456' => ['error' => 'Browser operation cancelled'],
])->run();
```

Both maps are keyed by tool call ID. Approval and execution are separate phases;
a result cannot answer a pending approval. Use `events()` instead of `run()` to
stream either continuation and obtain its final state from `getReturn()`.

### Case 5: Reading interrupt state

Before:

```php
$handler = $agent->chat($message);
if ($handler->interrupted()) {
    $request = $handler->getInterruptRequest();
    // ... collect decisions ...
    $handler = $agent->wake($payload);
}
```

After — interrupt state lives on the `AgentState`, the same place a plain
`WorkflowState` surfaces it. For an agent whose interruptions are tool approvals:

```php
$state = $agent->chat($message);
while ($state->isInterrupted()) {
    $request = $state->getInterruptRequest();
    // ... collect $decisions keyed by tool call ID ...
    $state = $agent->submitApprovalDecisions($decisions)->run();
}
```

### Case 6: Code that type-hinted `AgentHandler`

Any parameter, property, or return type typed `AgentHandler` must change. A handler was
either the eager result (now `AgentState`) or a streaming cursor (now a `Generator`):

Before:

```php
public function handle(AgentHandler $handler): void { /* ... */ }
```

After:

```php
public function handle(AgentState $state): void { /* ... */ }
```

## What NOT to Change

- The `AgentState` read surface is a superset of the old handler's convenience methods
  (`getMessage()`, `isInterrupted()`, `getInterruptRequest()`). Code that already chained
  off the result of `chat()` (e.g. `->getMessage()`) keeps working.
- `structured()` is unchanged — it still returns the typed output directly.
- Thread-first continuation still finds the run from the thread alone;
  `submitApprovalDecisions()` and `submitToolResults()` need no explicit run ID. (Guide 14 replaces the mechanism behind it:
  the thread itself becomes the run's workflow ID in workflow persistence.)
- The on-disk chat history format, persistence backends, and the approval flow are
  unaffected. This step only changes the Agent's public verb layer and its consumers.

## Verification Checklist

- [ ] No references to `AgentHandler` remain (imports, type hints, `new AgentHandler(...)`)
- [ ] No `->wake(` calls remain — Agent tool responses use `submitApprovalDecisions()` or `submitToolResults()`, followed by `run()` or `events()`
- [ ] No `->events(` calls remain on an Agent result — streaming iterates `stream()` directly
- [ ] No `->interrupted(` / `->getState(` / `->getProviderResponse(` remain on Agent results
- [ ] `->getResult()` after a consumed stream is replaced with `$generator->getReturn()`
- [ ] UI-adapter streaming configures the adapter through `setStreamAdapter()`
- [ ] The application's test suite and static analysis pass
