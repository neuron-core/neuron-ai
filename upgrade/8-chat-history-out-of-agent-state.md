# Chat history moves out of `AgentState`

`AgentState` used to carry the chat history, so every durable step snapshot
serialized the entire conversation — quadratic storage growth over long tool
loops, and a crash (`Serialization of 'PDO' is not allowed`) for SQL-backed
histories combined with workflow persistence. The history is now injected into
agent nodes as a constructor dependency and never enters the durable state.

## What breaks

### `AgentState` no longer holds the chat history

```php
// Before
$state->getChatHistory();
$state->setChatHistory($history);
$state->getMessage();

// After
$agent->getChatHistory();
$agent->setChatHistory($history);       // unchanged
$agent->chat($message)->getMessage();   // unchanged
```

### Agent node constructors take the history

Only relevant if you instantiate nodes directly:

```php
new ChatNode($provider, $chatHistory);
new StreamingNode($provider, $chatHistory);
new StructuredOutputNode($provider, $chatHistory, Output::class, 2);
new ToolNode($chatHistory, maxRuns: 5);
new ParallelToolNode($chatHistory, maxRuns: 5);
new PreProcessNode($chatHistory, $preProcessors);
```

Agent nodes implement `AgentNodeInterface`, which exposes `getChatHistory()`.

### Custom middleware that read the history from state

Extend the new `AgentMiddleware` base and use the typed hooks:

```php
class MyMiddleware extends AgentMiddleware
{
    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state): void
    {
        $history = $node->getChatHistory();
    }
}
```

`onAgentContextMismatch()` fires on misattachment outside the agent context —
override it to throw when a silent skip would be a safety hazard.

### `ChatHistoryHelper::addToChatHistory()` requires a memo name

History writes are wrapped in a durable memo so a crash-replay skips them
instead of duplicating the tail: `$this->addToChatHistory($messages, 'history.inbound')`.

## Behavior changes

- **Durable workflow persistence requires a comparably durable chat history.**
  `InMemoryChatHistory` no longer survives a cross-process resume by riding in
  the step snapshots (in-process resume is unaffected).
- **`getSteps()` is per-execution-cycle.** Still available on the final state,
  including on interruption, but transient: a resumed run reports only the
  messages produced since the resume. The full thread lives in the chat history.
- **SQL/Eloquent chat histories now work with durable workflow persistence.**
