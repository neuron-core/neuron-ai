# Chat history moves out of `AgentState`

The chat history is a runtime service bound to its own storage. It used to be
carried inside `AgentState`, which meant every durable step snapshot serialized
the entire conversation (twice: the history object plus the `__steps` copy) —
quadratic storage growth over long tool loops — and any PDO-backed history
crashed workflow persistence with `Serialization of 'PDO' is not allowed`.

The history is now injected into agent nodes as a constructor dependency and
never travels through the durable workflow state.

## What breaks

### `AgentState` no longer holds the chat history

```php
// Before
$state->getChatHistory();
$state->setChatHistory($history);
$state->getMessage();

// After — the agent owns the reference…
$agent->getChatHistory();
$agent->setChatHistory($history);   // unchanged

// …and nodes/middleware receive it (see below). The final message of a run:
$handler = $agent->chat($message);
$handler->getMessage();             // unchanged — reads the history tail
```

`AgentState::getSteps()` still reports the messages generated during the
execution cycle — including on an interrupted final state — but the accumulator
is now **transient**: it is excluded from durable snapshots (the duplicate
message copy was the second half of the quadratic snapshot growth), so a
*resumed* run reports only the messages produced since the resume, not the
whole thread. Read the full conversation from the chat history.

### Agent node constructors take the history

Only relevant if you instantiate nodes directly (custom workflows):

```php
// Before
new ChatNode($provider);
new StreamingNode($provider);
new StructuredOutputNode($provider, Output::class, 2);
new ToolNode(maxRuns: 5);
new ParallelToolNode(maxRuns: 5);
new PreProcessNode($preProcessors);

// After
new ChatNode($provider, $chatHistory);
new StreamingNode($provider, $chatHistory);
new StructuredOutputNode($provider, $chatHistory, Output::class, 2);
new ToolNode($chatHistory, maxRuns: 5);
new ParallelToolNode($chatHistory, maxRuns: 5);
new PreProcessNode($chatHistory, $preProcessors);
```

Agent nodes implement `AgentNodeInterface`, which exposes
`getChatHistory(): ChatHistoryInterface`.

### Custom middleware that read the history from state

`ToolApproval` and `Summarization` now extend the new `AgentMiddleware` base and
read the history from the node they wrap. If you wrote middleware that used
`$state->getChatHistory()`, extend `AgentMiddleware` and use the typed hooks:

```php
class MyMiddleware extends AgentMiddleware
{
    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state): void
    {
        $history = $node->getChatHistory();
        // ...
    }
}
```

`onAgentContextMismatch()` fires when the middleware is attached outside the
agent context — empty by default, override it to throw when a silent skip would
be a safety hazard (`ToolApproval` does).

## Behavior changes

- **Durable workflow persistence now requires a comparably durable chat
  history.** Previously `FilePersistence` + `InMemoryChatHistory` happened to
  survive a cross-process resume because the history rode inside the first step
  snapshot. That accidental rescue is gone: an in-memory history loses the
  thread across processes (in-process resume is unaffected). This was already
  the documented requirement for tool approval (ADR 0003).
- **History writes are durable memos.** Nodes re-run against the live history on
  crash-replay, so `addToChatHistory()` now wraps the write in `memoize()` (like
  tool execution and inference): on replay the recorded memo is recalled and the
  write is skipped instead of duplicating the tail. Custom nodes using the
  `ChatHistoryHelper` trait must pass a stable memo name:
  `$this->addToChatHistory($messages, 'history.inbound')`.
- **SQL/Eloquent chat histories now work with durable workflow persistence** —
  the state snapshot no longer serializes the history object.
