# Upgrade: `ChatHistoryInterface` is removed

## Summary

The working history is the concrete class `NeuronAI\Chat\History\ChatHistory`, opened for one
execution segment over a message store (guide 27). `ChatHistoryInterface` is removed in both of its
roles:

- **As a type.** Every method that returned or received a `ChatHistoryInterface` now declares
  `ChatHistory`, so implementations and overrides of those methods must follow.
- **As an implementation point.** Nothing accepts a custom history any longer. Storage varies through
  `MessageStoreInterface` (guide 27), the amount of conversation sent to the model through the
  context window (guide 27), and the choice of messages to drop through `HistoryTrimmerInterface`.

The Agent builds the history of each execution segment itself: its `chatHistory()` hook is removed
and `getChatHistory()` is final.

### Changed signatures

| Before | After |
|---|---|
| `AgentInterface::getChatHistory(): ChatHistoryInterface` | `AgentInterface::getChatHistory(): ChatHistory`, final on `Agent` |
| `AgentNodeInterface::getChatHistory(): ChatHistoryInterface` | `AgentNodeInterface::getChatHistory(): ChatHistory` |
| `AgentExecution::getChatHistory(): ChatHistoryInterface` | `AgentExecution::getChatHistory(): ChatHistory` |
| `Agent::chatHistory(string $threadId): ChatHistoryInterface` (protected hook) | Removed |
| Constructors of `ChatNode`, `StructuredOutputNode`, `ToolNode`, `ParallelToolNode` and `AwaitToolResultsNode`: `ChatHistoryInterface $chatHistory` | `ChatHistory $chatHistory` |
| Constructors of `PreProcessNode` and `ConversationIngestionNode`: `ChatHistoryInterface $chatHistory` | `ChatHistory $chatHistory` |
| `Trajectory::fromChatHistory(ChatHistoryInterface $chatHistory)` | `Trajectory::fromChatHistory(ChatHistory $chatHistory)` |
| `Summarization::summarizeHistory(ChatHistoryInterface $chatHistory, array $messages)` (protected) | `Summarization::summarizeHistory(ChatHistory $chatHistory, array $messages)` |

### Former interface methods

| `ChatHistoryInterface` | `ChatHistory` |
|---|---|
| `setThreadId(string $threadId): void` | Removed: the thread is a constructor argument |
| `getThreadId(): ?string` | `getThreadId(): string` |
| `addMessage(Message $message): ChatHistoryInterface` | `addMessage(Message $message): self` |
| `getMessages(): array` | Unchanged |
| `getLastMessage(): Message\|false` | `getLastMessage(): Message`: an empty history throws `ChatHistoryException` |
| `flushAll(): ChatHistoryInterface` | `flushAll(): self` |
| `calculateTotalUsage(): int` | Unchanged |

## How to Refactor

### Case 1: Type declarations

Replace the interface with the class in properties, parameters and return types, including
overrides of `Summarization::summarizeHistory()`.

Before:

```php
use NeuronAI\Chat\History\ChatHistoryInterface;

protected function lastAnswer(ChatHistoryInterface $history): ?string
{
    return $history->getLastMessage()->getContent();
}
```

After:

```php
use NeuronAI\Chat\History\ChatHistory;

protected function lastAnswer(ChatHistory $history): ?string
{
    return $history->getLastMessage()->getContent();
}
```

### Case 2: A custom agent node

A class implementing `AgentNodeInterface` must declare the new return type, otherwise PHP refuses to
load it.

Before:

```php
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Workflow\Node;

class AuditNode extends Node implements AgentNodeInterface
{
    public function __construct(protected ChatHistoryInterface $chatHistory)
    {
    }

    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->chatHistory;
    }

    // ...
}
```

After:

```php
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Workflow\Node;

class AuditNode extends Node implements AgentNodeInterface
{
    public function __construct(protected ChatHistory $chatHistory)
    {
    }

    public function getChatHistory(): ChatHistory
    {
        return $this->chatHistory;
    }

    // ...
}
```

A node using the `ChatHistoryHelper` trait, or extending a built-in node, only changes the type of
its own constructor parameter. Construct custom nodes with the history of the segment,
`$execution->getChatHistory()`, in `nodes()`, `entryNodes()` or `exitNodes()`.

### Case 3: A custom `ChatHistoryInterface` implementation

Move what the implementation did to the extension point it belongs to:

- **Where messages are stored**: implement `MessageStoreInterface` (guide 27, Case 4).
- **How much of the conversation the model sees**: override the Agent's `contextWindow()` hook or
  call `setContextWindow()` (guide 27, Case 2).
- **Which messages are dropped**: implement `HistoryTrimmerInterface`. A workflow you compose
  yourself passes it to the history it builds, a new trimmer for every history because trimmers keep
  state:

```php
$history = new ChatHistory($store, $threadId, $contextWindow, new KeepLastTurnsTrimmer());
```

Agents always use the default `HistoryTrimmer`.

### Case 4: An Agent overriding `chatHistory()` or `getChatHistory()`

Remove the override. The Agent builds the history of every execution segment from `messageStore()`
and `contextWindow()`, where guide 27 moved the store and the context window. `getChatHistory()` is
final and returns a fresh view on every call.

### Case 5: Test doubles

A mock or stub of `ChatHistoryInterface` has nothing left to replace. Use a real history over an
in-memory store; it performs no I/O.

Before:

```php
$history = $this->createMock(ChatHistoryInterface::class);
$history->method('getMessages')->willReturn([$question, $answer]);
```

After:

```php
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;

$history = new ChatHistory(new InMemoryMessageStore(), 'thread');
$history->addMessage($question);
$history->addMessage($answer);
```

### Case 6: Comparing `getLastMessage()` with `false`

`getLastMessage()` never returns `false`: an empty history throws `ChatHistoryException`, as the
built-in histories already did. Where an empty history is expected, check `getMessages() === []`
first and remove the `false` branch.

## What to Search For

```
grep -rn "ChatHistoryInterface" --include="*.php" .
grep -rn "implements AgentNodeInterface" --include="*.php" .
grep -rnE "function (chatHistory|getChatHistory|summarizeHistory)\(" --include="*.php" .
grep -rn "getLastMessage()" --include="*.php" .
```

## Checklist

- No reference to `ChatHistoryInterface` remains, in application code, tests or configuration.
- Custom agent nodes declare `getChatHistory(): ChatHistory`.
- No Agent subclass defines `chatHistory()` or `getChatHistory()`.
- No code compares the result of `getLastMessage()` with `false`.
