# Chat history moves out of `AgentState`

`AgentState` used to carry the chat history, so every durable step snapshot
serialized the entire conversation — quadratic storage growth over long tool
loops, and a crash (`Serialization of 'PDO' is not allowed`) for SQL-backed
histories combined with workflow persistence. The history is now a resource of
the execution segment, handed to agent nodes and middleware, and never enters the
durable state.

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

### Custom nodes that read the history from state

Declare the segment's resources as the third `__invoke()` parameter and read the
history there (guide 36 covers the other collaborators):

```php
public function __invoke(AgentOutputEvent $event, AgentState $state, AgentResources $resources): StopEvent
{
    $history = $resources->history;
    // ...
}
```

### Custom middleware that read the history from state

Extend the new `AgentMiddleware` base and use the typed hooks, which receive the
same resources:

```php
class MyMiddleware extends AgentMiddleware
{
    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
    {
        $history = $resources->history;
    }
}
```

`onAgentContextMismatch()` fires on misattachment outside the agent context —
override it to throw when a silent skip would be a safety hazard.

### `ChatHistoryHelper::addToChatHistory()` takes the history and a memo name

History writes are wrapped in a durable memo so a crash-replay skips them
instead of duplicating the tail:
`$this->addToChatHistory($resources->history, $state, $messages, 'history.inbound')`.

## Behavior changes

- **Durable workflow persistence requires a comparably durable chat history.**
  `InMemoryChatHistory` no longer survives a cross-process resume by riding in
  the step snapshots (in-process resume is unaffected).
- **`getSteps()` is per-execution-cycle.** Still available on the final state,
  including on interruption, but transient: a resumed run reports only the
  messages produced since the resume. The full thread lives in the chat history.
- **SQL/Eloquent chat histories now work with durable workflow persistence.**
