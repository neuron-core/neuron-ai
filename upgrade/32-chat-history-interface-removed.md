# Upgrade: ChatHistoryInterface is removed

## Summary

`NeuronAI\Chat\History\ChatHistoryInterface` is removed. A conversation's history is now the concrete class `NeuronAI\Chat\History\ChatHistory`, with this constructor:

```php
public function __construct(
    MessageStoreInterface $store,
    string $threadId,
    int $contextWindow = ChatHistory::DEFAULT_CONTEXT_WINDOW, // 50000 tokens
    HistoryTrimmerInterface $trimmer = new HistoryTrimmer(),
    float $historyTrimRatio = ChatHistory::DEFAULT_HISTORY_TRIM_RATIO, // 0.5
)
```

No API accepts a custom history anymore. Storage is a `MessageStoreInterface`, the size limit is the context window in tokens, and a custom dropping policy is a `HistoryTrimmerInterface`.

| 3.x | 4.x |
|---|---|
| `ChatHistoryInterface` in types, `instanceof`, docblocks and mocks | `ChatHistory` (Case 1) |
| `addMessage(Message): ChatHistoryInterface` | `addMessage(Message): self`. A message whose ID is already in the history is skipped (Case 5) |
| `getMessages(): array` | unchanged |
| `getLastMessage(): Message\|false` | `getLastMessage(): Message`. An empty history throws `NeuronAI\Exceptions\ChatHistoryException` (Case 2) |
| `flushAll(): ChatHistoryInterface` | `flushAll(): self` |
| `calculateTotalUsage(): int` | unchanged |
| none | `getThreadId(): string` |
| `class X implements ChatHistoryInterface` | a `MessageStoreInterface` plus the context window (Cases 3 and 4) |
| `$agent->getChatHistory()` returning `ChatHistoryInterface` | returns `ChatHistory`. Calls stay the same |
| sequence validation in `addMessage()` | with the default trimmer, it also rejects a history that opens with a `ToolCallMessage`, and a `UserMessage` directly after a `ToolCallMessage` (Case 4) |
| `HistoryTrimmerInterface` | the interface is unchanged, but implementations must follow three new rules (Case 6) |
| a history over its window loses the fewest oldest messages that make it fit | it is cut down to half the window. A history trim ratio of 0 keeps the 3.x cut (Case 9) |
| `HistoryTrimmer` protected methods | new signatures (Case 7) |
| `new TokenCounter($charsPerToken, $extraTokensPerMessage)` | `new TokenCounter($charsPerToken)` (Case 8) |

Other guides own these. Leave them alone here:

- History backends, `setChatHistory()`, the Agent's `chatHistory()` hook, `getChatHistory()` overrides, history container bindings, and a custom trimmer given to an Agent's history: guide 31 (already applied). Exception: when one of these uses a history class the application wrote itself (a class that implements `ChatHistoryInterface` directly), Case 3 migrates that use together with the class.
- `AgentState::getChatHistory()`: guide 23. `Summarization::summarizeHistory()` overrides: guide 27.

Nothing in this guide changes stored data.

## What to Search For

Run from the application root:

```bash
grep -rn 'ChatHistoryInterface' --include='*.php' --exclude-dir=vendor .
grep -rn 'ChatHistoryInterface' --include='*.yaml' --include='*.yml' --include='*.xml' --include='*.neon' --exclude-dir=vendor .
grep -rn 'getLastMessage' --include='*.php' --exclude-dir=vendor .
grep -rnE '\bclone\s*\(?\s*\$' --include='*.php' --exclude-dir=vendor .
grep -rnE 'ToolCallMessage|Invalid message sequence' --include='*.php' --exclude-dir=vendor .
grep -rnE 'HistoryTrimmerInterface|extends\s+HistoryTrimmer\b|TokenCounter\b|extraTokensPerMessage' --include='*.php' --exclude-dir=vendor .
grep -rnE 'setContextWindow[(]|function contextWindow[(]|new ChatHistory[(]' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:

- **`ChatHistoryInterface`** in a type, `instanceof`, docblock or mock: Case 1. `implements ChatHistoryInterface` in production code: Case 3. In test code: Case 4. Also update the classes that extend a hit, and the call sites that construct it.
- Guide 31 should have removed these, so apply guide 31 first to any that remain:
  - a `chatHistory()` or `getChatHistory()` method on an Agent/RAG subclass;
  - a `setChatHistory()` call;
  - a container binding or service definition keyed by the interface.

  The exception is a history class the application wrote itself: Case 3 migrates it, together with the hook, call or binding that uses it.
- **`getLastMessage`**: Case 2 applies when the result is compared with `false`, checked for falsiness (`!`, `?:`, `if ($last)`), or when a mock returns `false`.
- **`clone`**: Case 5 applies when the cloned value is a message. Also look for one message object sent more than once: in loops, in retries, or kept in a property.
- **`ToolCallMessage` / `Invalid message sequence`**: Case 4 applies where a `ToolCallMessage` is written into a history or store by hand in tests, fixtures, seeders or imports: `addMessage()` (including on `$agent->getChatHistory()`, as guide 31 seeds histories), `new ChatHistory(...)`, or a store's `append()`. A `ToolCallMessage` that is only queued as a `FakeAIProvider` response needs no change. Both sequences that 4.x newly rejects contain a `ToolCallMessage`.
- **Trimmer and `TokenCounter` hits**: Cases 6 to 8.
- **`setContextWindow(`, `contextWindow()` and `new ChatHistory(`**: Case 9, for every Agent or history whose context window the application sets.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: Type declarations, `instanceof`, docblocks and mocks

Replace `ChatHistoryInterface` with `ChatHistory` in parameter, property and return types, `instanceof` checks, `@var`/`@param`/`@return` docblocks and mocks. Change the import. Take care with `instanceof ChatHistoryInterface`: PHP does not report a missing class there, so the check just evaluates to `false`.

Before (3.x):

```php
use NeuronAI\Chat\History\ChatHistoryInterface;

class TranscriptExporter
{
    public function __construct(protected ChatHistoryInterface $history)
    {
    }

    public static function supports(mixed $source): bool
    {
        return $source instanceof ChatHistoryInterface;
    }
}

// In a test
$history = $this->createMock(ChatHistoryInterface::class);
$history->method('getMessages')->willReturn([$question, $answer]);
```

After (4.x):

```php
use NeuronAI\Chat\History\ChatHistory;

class TranscriptExporter
{
    public function __construct(protected ChatHistory $history)
    {
    }

    public static function supports(mixed $source): bool
    {
        return $source instanceof ChatHistory;
    }
}

// In a test
$history = $this->createMock(ChatHistory::class);
$history->method('getMessages')->willReturn([$question, $answer]);
```

If the file already imports another class named `ChatHistory` (for example an Eloquent model), import this one with an alias: `use NeuronAI\Chat\History\ChatHistory as NeuronChatHistory;`. Callers such as `new TranscriptExporter($agent->getChatHistory())` need no change.

### Case 2: `getLastMessage()` compared with `false`

`getLastMessage()` never returns `false`: an empty history throws `ChatHistoryException`. Check for an empty history first, and remove the `false` branch.

Before (3.x):

```php
$last = $history->getLastMessage();

return $last === false ? null : $last->getContent();
```

After (4.x):

```php
return $history->getMessages() === [] ? null : $history->getLastMessage()->getContent();
```

A mock that returned `false` must throw instead. PHPUnit refuses `willReturn(false)` for this method:

```php
use NeuronAI\Exceptions\ChatHistoryException;

$history->method('getLastMessage')->willThrowException(new ChatHistoryException('No messages in the chat history.'));
```

### Case 3: A production class implementing `ChatHistoryInterface`

Before (3.x, after guide 13 bound the thread ID; method bodies shortened):

```php
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;

class RedisChatHistory implements ChatHistoryInterface
{
    public function __construct(protected \Redis $redis, protected string $threadId, protected int $maxMessages = 40)
    {
    }

    public function addMessage(Message $message): ChatHistoryInterface { /* RPUSH the JSON, then LTRIM to $maxMessages */ }
    public function getMessages(): array { /* LRANGE and decode */ }
    public function getLastMessage(): Message|false { /* ... */ }
    public function flushAll(): ChatHistoryInterface { /* DEL */ }
    public function calculateTotalUsage(): int { /* ... */ }
    public function jsonSerialize(): array { return $this->getMessages(); }
}

// Used by an Agent
$agent = SupportAgent::make(workflowId: $threadId)->setChatHistory(new RedisChatHistory($redis, $threadId));

// Used by application code
$history = new RedisChatHistory($redis, $threadId);
```

1. Turn the storage into a class that implements `NeuronAI\Chat\History\MessageStoreInterface`, for example `RedisMessageStore`. Build it the way guide 31 converts an `AbstractChatHistory` subclass: it has a store skeleton, message decoding with `MessageDeserializer`, and pagination with `PaginatesMessages`. Map the members like this:

   | In the 3.x class | In the store |
   |---|---|
   | thread ID in the constructor | removed. Every store method takes `string $threadId` |
   | the write in `addMessage()` | `append(string $threadId, Message $message): void`. Skip a message whose `getId()` is already stored in the thread |
   | `getMessages()` | `loadActive(string $threadId): array`: the messages that are not archived, in insertion order |
   | code that deleted old messages | `archive(string $threadId, int $count): void`. Mark the oldest `$count` active messages as archived and keep them. `ChatHistory` calls this when it trims |
   | `flushAll()` | `clear(string $threadId): void`. Archived messages are removed too |
   | none | `loadAll(string $threadId, ?int $limit = null, ?string $before = null): array`: all messages, archived ones included |
   | `getLastMessage()`, `calculateTotalUsage()`, `jsonSerialize()`, the size limit | delete them. `ChatHistory` provides these |

2. `loadActive()` must read what the 3.x class stored, under the same keys. If the class stored `json_encode($message)`, the rows load with `(new \NeuronAI\Chat\Messages\MessageDeserializer())->deserialize(json_decode($row, true))`. That call reads the 3.x shapes and keeps each message's ID. Any other format, such as PHP `serialize()`: tell the developer that the stored threads must be checked against the 4.x message classes before release.
3. Move the dropping policy:
   - A size cap (the last N messages, or a token budget) becomes the context window, measured in tokens. When the 3.x cap was a message count, ask the developer which token budget to use (the default is 50000).
   - Any other policy: ask the developer whether to keep it. If they keep it, implement it as a `HistoryTrimmerInterface` that follows Case 6. Pass it as the fourth `ChatHistory` argument. For an Agent, guide 31 shows how an Agent's history receives a custom trimmer.
4. Replace the uses, and delete the 3.x class.

After (4.x):

```php
use NeuronAI\Chat\History\ChatHistory;

// Agent
$agent = SupportAgent::make(workflowId: $threadId)
    ->setMessageStore(new RedisMessageStore($redis))
    ->setContextWindow(30000);

// Application code
$history = new ChatHistory(new RedisMessageStore($redis), $threadId, 30000);
```

Other uses of the 3.x class:

- A `chatHistory()` hook that returned it becomes a `messageStore(): MessageStoreInterface` hook returning the store, plus a `contextWindow(): int` hook when the window is not 50000.
- A container binding of it becomes a binding of the store, as guide 31 does for history bindings.
- Type hints of it become `ChatHistory` (Case 1).

Leave out `setContextWindow()` and `contextWindow()` when the 3.x class had no size limit.

### Case 4: Test stubs and hand-built histories

Replace a test stub that implemented the interface with a real history over an in-memory store. Delete the stub class.

Before (3.x):

```php
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;

class StubChatHistory implements ChatHistoryInterface
{
    /** @param Message[] $messages */
    public function __construct(protected array $messages = [])
    {
    }

    // addMessage(), getMessages(), getLastMessage(), flushAll(), calculateTotalUsage(), jsonSerialize() over $this->messages
}

$history = new StubChatHistory([$question, $answer]);
```

After (4.x):

```php
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;

$history = new ChatHistory(new InMemoryMessageStore(), 'test-thread');
$history->addMessage($question);
$history->addMessage($answer);
```

A stub that was given to an Agent was already replaced by a seeded store in guide 31.

With the default trimmer, `addMessage()` validates the whole sequence and throws `ChatHistoryException('Invalid message sequence ...')` unless all of the following hold:

- the first message is a `UserMessage`;
- user and assistant messages alternate;
- every `ToolCallMessage` is followed by its `ToolResultMessage` before the next `UserMessage`.

3.x enforced the same rules, except two sequences it accepted and 4.x rejects: a history that opens with a `ToolCallMessage`, and a `UserMessage` directly after a `ToolCallMessage`. A stub validated nothing. Fix the order in fixtures, seeders and imports that build histories by hand. A test that expected one of those two sequences to be accepted must now expect `ChatHistoryException`.

### Case 5: The same message object, or a clone of it, added again

`ChatHistory::addMessage()` skips a message whose `getId()` is already in the history, and `clone` keeps the ID. The Agent writes through `addMessage()` too, so sending one message object to `chat()`, `stream()` or `structured()` a second time on the same thread throws `ChatHistoryException`. 3.x added the message again in both cases. Give every copy a new ID, or build a new message.

Before (3.x):

```php
$history->addMessage(clone $template);

$agent->chat($continue);   // $continue was already sent on this thread
```

After (4.x):

```php
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\UniqueIdGenerator;

$history->addMessage((clone $template)->setId(UniqueIdGenerator::generateId('msg_')));

$agent->chat(new UserMessage('Continue'));
```

If the application removed duplicates before calling `addMessage()`, it no longer needs to, and you may delete that code.

### Case 6: Custom `HistoryTrimmerInterface` implementations

The interface is unchanged: `getTotalTokens(): int` and `trim(array $messages, int $contextWindow): array`. Check every implementation against three rules:

1. `trim()` may only drop messages from the start. It must return the newest messages of its input as the same objects, in the same order. `ChatHistory` stores only the added message, then archives as many of the oldest stored messages as `trim()` dropped. Messages that the trimmer inserts, rewrites, reorders or removes from the middle never reach the store. 3.x `SQLChatHistory` and `FileChatHistory` saved whatever `trim()` returned. If the trimmer does more than drop the oldest messages, report it to the developer: that logic cannot stay in a trimmer. Summaries belong to the `Summarization` middleware.
2. `ChatHistory::calculateTotalUsage()` now calls `trim($messages, PHP_INT_MAX)` and then `getTotalTokens()`. In 3.x it read the total left by the last trim. With `PHP_INT_MAX` as the window, `trim()` must return every message and set the total. Look for arithmetic on `$contextWindow` that overflows, such as `(int) ($contextWindow * 1.1)`.
3. After a `trim()` that dropped messages, `ChatHistory` calls `trim()` again, on the messages it returned and with a smaller window (Case 9). A trimmer that ignores the window returns them unchanged. If a trimmer drops a fixed amount on every call, such as one turn, it now drops it twice: report it to the developer.

Pass a new trimmer instance to each `ChatHistory`. Guide 31 covers a trimmer given to an Agent.

### Case 7: Subclasses of `HistoryTrimmer`

Prefer implementing `HistoryTrimmerInterface` (Case 6). If you keep the subclass, update its overrides and calls to `parent::`:

| 3.x protected member | 4.x |
|---|---|
| `getCheckpoints(array $messages, int $count, string $hash): array` | `getCheckpoints(array $messages): array` |
| `findTrimPoint(array $messages, array $checkpoints, int $contextWindow): array` | `findTrimPoint(array $messages, int $contextWindow): array` |
| `adjustTrimIndex(array $messages, int $trimIndex, int $tokens): array` | `adjustTrimIndex(array $messages, int $trimIndex, int $contextWindow): array`. The third argument is now the window |
| `updateTokensFromMessage(Message $message, int $tokens): int` | removed. Delete the override |
| `findTrimIndexByEstimation(array $messages, int $contextWindow): int` | removed. The closest method is `findTrimIndex(array $messages, int $contextWindow): int` |
| `$cachedCheckpoints`, `$cachedCount`, `$cachedLastHash` | removed |

4.x adds the protected members `findTrimIndex()`, `nearestUserMessage()`, `cutAt()`, `messageTokens()` and the constant `OVERFLOW_TOLERANCE`. Rename subclass members that use these names. `normalizeCheckpoints()`, `calculateTotal()`, `isUserMessage()`, `estimateTokens()`, `validateAlternation()` and the constructor are unchanged.

Before (3.x):

```php
use NeuronAI\Chat\History\HistoryTrimmer;

class EagerTrimmer extends HistoryTrimmer
{
    protected function findTrimPoint(array $messages, array $checkpoints, int $contextWindow): array
    {
        return parent::findTrimPoint($messages, $checkpoints, (int) ($contextWindow * 0.8));
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\History\HistoryTrimmer;

class EagerTrimmer extends HistoryTrimmer
{
    protected function findTrimPoint(array $messages, int $contextWindow): array
    {
        return parent::findTrimPoint($messages, (int) ($contextWindow * 0.8));
    }
}
```

A subclass that only cuts deeper, as this one does, now adds to the history's own deeper cut (Case 9): once over the window it is asked for 80% of the window, then for 80% of half of it. Ask the developer whether a trim ratio replaces the subclass.

### Case 8: `TokenCounter`

The second constructor parameter, `$extraTokensPerMessage`, is removed. 3.x never used it. A named `extraTokensPerMessage:` argument now fails with "Unknown named parameter", and PHP silently ignores a positional second argument. Remove it, including from `parent::__construct()` calls in subclasses.

Before (3.x):

```php
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\TokenCounter;

$trimmer = new HistoryTrimmer(new TokenCounter(3.5, 3.0));
$counter = new TokenCounter(charsPerToken: 3.5, extraTokensPerMessage: 3.0);
```

After (4.x):

```php
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\TokenCounter;

$trimmer = new HistoryTrimmer(new TokenCounter(3.5));
$counter = new TokenCounter(charsPerToken: 3.5);
```

A `TokenCounter` subclass whose own code reads `$this->extraTokensPerMessage` must declare the property itself:

```php
use NeuronAI\Chat\History\TokenCounter;

class PaddedTokenCounter extends TokenCounter
{
    public function __construct(float $charsPerToken = 4.0, protected float $extraTokensPerMessage = 3.0)
    {
        parent::__construct($charsPerToken);
    }
}
```

4.x adds the protected method `handleToolCalls(ToolCallMessage $message): int`. Rename a subclass method that uses that name.

### Case 9: How far a full history is cut

In 3.x a history over its context window lost the fewest oldest messages that made it fit again, so once the window was full the first message changed at almost every turn. Providers cache a request from its start, and such a history is never read from the prompt cache. In 4.x a history over its window is cut down to a share of it: half by default (`ChatHistory::DEFAULT_HISTORY_TRIM_RATIO`, 0.5), which leaves the first message in place for many turns. Nothing fails to compile or run. The model sees less of the conversation right after a cut.

1. Do not change the ratio on your own. Tell the developer about the new cut for each Agent or history found by the search, and ask whether to keep it.
2. To keep the 3.x cut, set the ratio to 0. Any value from 0 up to, not including, 1 is accepted, and another value throws `NeuronAI\Exceptions\ChatHistoryException`. The ratio is the share of the window a cut frees, so 0.2 cuts a full history down to 80% of its window.

```php
// Agent
$agent->setHistoryTrimRatio(0);

// Agent or RAG subclass
protected function historyTrimRatio(): float
{
    return 0.0;
}

// Application code: the fifth ChatHistory argument
$history = new ChatHistory($store, $threadId, 30000, historyTrimRatio: 0);
```

An explicit `setHistoryTrimRatio()` wins over the hook.

## Checklist

- [ ] No `ChatHistoryInterface` is left in application code, tests or configuration.
- [ ] Every `instanceof` check and mock that named the interface now names `ChatHistory`.
- [ ] No code compares `getLastMessage()` with `false` or checks it for falsiness, and no mock returns `false` from it.
- [ ] No application class implements the interface. Custom storage is a `MessageStoreInterface` that reads the rows the 3.x class wrote, and the developer decided on any custom dropping policy.
- [ ] Hand-built histories open with a `UserMessage`, and every `ToolCallMessage` is followed by its `ToolResultMessage` before the next `UserMessage`.
- [ ] Every message added again, or sent to an Agent again, is a new message or has a new ID.
- [ ] Custom trimmers only drop the oldest messages, return every message when the window is `PHP_INT_MAX`, and can be called again on their own result.
- [ ] The developer decided whether each Agent and history with its own context window keeps the 4.x cut or a trim ratio of 0.
- [ ] `HistoryTrimmer` subclasses use the 4.x signatures and override no removed member.
- [ ] No `TokenCounter` is constructed with a second argument.
- [ ] PHPStan reports no error about `ChatHistoryInterface`, `getLastMessage()`, `HistoryTrimmer` or `TokenCounter`.
