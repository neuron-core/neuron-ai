# Upgrade: Chat history backends become message stores

## Summary

The 3.x history backends `InMemoryChatHistory`, `SQLChatHistory`, `EloquentChatHistory` and `FileChatHistory`, and their base class `AbstractChatHistory`, are removed. 4.x stores conversations in message stores that implement `MessageStoreInterface`, in the same namespace `NeuronAI\Chat\History`. A store holds no thread and no context window: every method takes the thread ID. The Agent builds the conversation history from its store, its context window and its thread ID. `RAG` extends `Agent`, so everything here applies to RAG too.

| 3.x | 4.x |
|---|---|
| `new SQLChatHistory($threadId, $pdo, $table, $contextWindow)` | `new SQLMessageStore($pdo)`, on the per-message table from guide 30 (Case 1, step 2) |
| `new EloquentChatHistory($threadId, ChatMessage::class, $contextWindow)` | `new EloquentMessageStore(ChatMessage::class)` |
| `new FileChatHistory($directory, $key, $contextWindow, $prefix, $ext)` | `new FileMessageStore($directory, $prefix, $ext)` |
| `new InMemoryChatHistory($contextWindow, $trimmer)` | `new InMemoryMessageStore()`, the Agent's default |
| `class X extends AbstractChatHistory` | `class X implements MessageStoreInterface` |
| `$agent->setChatHistory($history)` | `$agent->setMessageStore($store)` |
| `protected function chatHistory(): ChatHistoryInterface` | `protected function messageStore(): MessageStoreInterface` |
| the history's `$contextWindow` | `$agent->setContextWindow(int $tokens)` or `protected function contextWindow(): int`; default `ChatHistory::DEFAULT_CONTEXT_WINDOW` (50000) |
| the history's thread ID (file: `$key`) | the Agent's thread ID, already bound by guide 13 |
| overridable `getChatHistory()` | `final public function getChatHistory(): ChatHistory` |

Before this guide, guide 30 (tables, Eloquent columns, file names) and guide 13 (thread IDs) must already be applied.

What breaks if nothing changes:
- A `chatHistory()` override left in place is never called. The Agent silently falls back to `InMemoryMessageStore`, and conversations stop persisting.
- `setChatHistory()` is an undefined method.
- A `getChatHistory()` override is a fatal error because the method is final.
- A `$contextWindow` or `$messageStore` property declared in an Agent or RAG subclass collides with the 4.x Agent's own property of that name: a different type is a fatal error at class load.

Stored data: this guide changes none.
- The stores read the tables and files as guide 30 left them.
- 3.x `.chat` files load as they are.
- Entries that a custom backend stored with 3.x (the `Message::jsonSerialize()` shape) are read as-is by `MessageDeserializer` (Case 7).

`ChatHistoryInterface` itself, the `ChatHistory` API and trimming rules are migrated by guide 32.

## What to Search For

Run from the application root:

```bash
# 1. The removed backends: constructions, imports, subclasses, container definitions
grep -rnE '(InMemory|SQL|File|Eloquent|Abstract)ChatHistory([^A-Za-z0-9_]|$)' --include='*.php' --include='*.yaml' --include='*.yml' --include='*.xml' --exclude-dir=vendor .

# 2. The Agent setter, the hook, and overrides of the now-final getter
grep -rnE 'setChatHistory[(]|function (chatHistory|getChatHistory)[(]' --include='*.php' --exclude-dir=vendor .

# 3. 3.x backend hooks and helpers in custom backends
grep -rnE 'function (setMessages|onNewMessage|onTrimHistory|getFilePath)[(]|deserializeMessages[(]' --include='*.php' --exclude-dir=vendor .

# 4. Properties that collide with the 4.x Agent's own
grep -rnE '(public|protected|private|var)[^;=(]*[$](contextWindow|messageStore)([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .

# 5. Message subclasses (Case 10)
grep -rnE 'extends ([\\A-Za-z0-9_]*\\)?(Message|UserMessage|AssistantMessage|ToolCallMessage|ToolResultMessage)([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .
```

Then follow the hits:
- For every class that extends a removed backend, grep its class name to find its constructions and its own subclasses.
- For every Agent or RAG class with a hit, check its subclasses, and every place it is constructed or resolved: controllers, jobs, commands, container bindings and tests.
- Hits of search 4 matter only in classes that extend Agent or RAG, directly or not.
- Hits of search 5 matter only when the class extends a Neuron message class and declares `getId()` or `setId()`.
- A `setChatHistory()` call on an `AgentState` (a `state()` override or a seed state): guide 23 Case 5 (already applied) removes it. Apply it first, then continue here.
- A class that implements `AgentInterface` directly and declares its own `setChatHistory()`: leave it for guide 57, which gives the full 4.x contract.
- A `setChatHistory()` call, a `chatHistory()`/`getChatHistory()` override or a container binding whose history is a production class the application wrote that implements `ChatHistoryInterface` directly (not a subclass of `AbstractChatHistory` or of a built-in backend, and not a test mock or stub, which Case 2 handles): leave it and the class for guide 32 Case 3.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: A backend passed to `setChatHistory()`

Before (3.x, with the thread bound by guide 13):

```php
use NeuronAI\Chat\History\SQLChatHistory;

$agent = SupportAgent::make(workflowId: $threadId)
    ->setChatHistory(new SQLChatHistory($threadId, $pdo, contextWindow: 100000));
```

After (4.x):

```php
use NeuronAI\Chat\History\SQLMessageStore;

$agent = SupportAgent::make(workflowId: $threadId)
    ->setMessageStore(new SQLMessageStore($pdo))
    ->setContextWindow(100000);
```

Steps:

1. Rewrite the constructor, replacing the old import with the new one:

   | 3.x (named arguments) | 4.x (named arguments) |
   |---|---|
   | `new SQLChatHistory($threadId, $pdo[, $table[, $contextWindow]])` (`thread_id:`, `pdo:`, `table:`, `contextWindow:`) | `new SQLMessageStore($pdo[, $table])` (`pdo:`, `table:`) |
   | `new EloquentChatHistory($threadId, $modelClass[, $contextWindow])` (`threadId:`, `modelClass:`, `contextWindow:`) | `new EloquentMessageStore($modelClass)` (`modelClass:`) |
   | `new FileChatHistory($directory, $key[, $contextWindow[, $prefix[, $ext]]])` (`directory:`, `key:`, `contextWindow:`, `prefix:`, `ext:`) | `new FileMessageStore($directory[, $prefix[, $ext]])` (`directory:`, `prefix:`, `ext:`), see Case 4 |
   | `new InMemoryChatHistory([$contextWindow[, $trimmer]])` (`contextWindow:`, `trimmer:`) | `new InMemoryMessageStore()`; a `$trimmer`: Case 9 |

2. SQL table: `SQLMessageStore` reads the per-message table that guide 30 created, never the 3.x table (`chat_history` by default, or the name the 3.x code passed). Omit `$table` when that table is `chat_messages`, and pass its name otherwise. When the name comes from configuration, point that setting at the per-message table.
3. Thread ID (`$threadId`, `thread_id:`, the file `$key`): drop it. Check that the Agent is bound to the same value, through `make(workflowId:)`, `setThreadId()`, `for()` or a `workflowId()` override from guide 13. If it is not, bind it as guide 13 Case 5 shows.
4. Context window: drop it from the constructor. When it differs from the window the Agent class uses without it (the value of its `chatHistory()` or `contextWindow()` hook, else 50000), call `->setContextWindow($contextWindow)` on the Agent, or override `contextWindow()` (Case 3).
5. `setChatHistory(new InMemoryChatHistory(...))`: replace it with `setMessageStore(new InMemoryMessageStore())`. You can drop the call instead when neither the Agent class nor its parents override `messageStore()` or `chatHistory()` (Case 3 turns `chatHistory()` into `messageStore()`), because in-memory is the default. Keep a non-default window with `setContextWindow()`.
6. Store constructors no longer touch the database or the file system, so the 3.x `ChatHistoryException`s 'Table not allowed', 'Unsupported database driver' and 'Directory ... could not be created' never come from construction. A missing table fails on first use with a `PDOException`, and a directory that cannot be created fails with `ChatHistoryException` on the first write. A `try`/`catch` around a constructor that expected those exceptions no longer fires: move it around the first use, or delete it. Only an invalid table name (`ChatHistoryException: Invalid table name ...`) and the Case 4 prefix check still throw from a constructor.

An explicit `setMessageStore()` or `setContextWindow()` wins over the Agent's `messageStore()` or `contextWindow()` hook.

### Case 2: A history filled before it was passed to the Agent

Tests, seeders and imports that added messages to a history and then passed it to `setChatHistory()` now write through the Agent's own history.

Before (3.x):

```php
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;

$history = new InMemoryChatHistory();
$history->addMessage(new UserMessage("Hi, I'm Valerio"));
$history->addMessage(new AssistantMessage('Hello Valerio!'));

$agent = SupportAgent::make(workflowId: 'thread-1')->setChatHistory($history);
```

After (4.x):

```php
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;

$agent = SupportAgent::make(workflowId: 'thread-1');

$history = $agent->getChatHistory();
$history->addMessage(new UserMessage("Hi, I'm Valerio"));
$history->addMessage(new AssistantMessage('Hello Valerio!'));
```

- When the 3.x history was durable, call `setMessageStore()` first (Case 1), so the messages go to that store.
- When the 3.x history was an `InMemoryChatHistory` and the Agent class or a parent overrides `messageStore()` or `chatHistory()`, call `->setMessageStore(new InMemoryMessageStore())` before `getChatHistory()` (Case 1, step 5), so the seeded messages stay out of the durable store.
- Write before the run starts, never while it runs.
- A mock or stub of `ChatHistoryInterface` passed to `setChatHistory()`: seed the messages its `getMessages()` returned in the same way, and delete the mock. Assertions on its `addMessage()` calls become assertions on `$agent->getChatHistory()->getMessages()` after the run.
- One history object passed to several agents shared one conversation between them. Give each agent the same store instance and bind all of them to the same thread ID.
- The seeded messages must open with a `UserMessage`, and every `ToolCallMessage` must be followed by its `ToolResultMessage`. Guide 32 covers the sequence rules.

### Case 3: The `chatHistory()` hook

Before (3.x, with the constructor from guide 13):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\EloquentChatHistory;

class SupportAgent extends Agent
{
    protected int $contextWindow = 100000;

    public function __construct(protected string $threadId)
    {
        parent::__construct($threadId);
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return new EloquentChatHistory($this->threadId, ChatMessage::class, $this->contextWindow);
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;

class SupportAgent extends Agent
{
    public function __construct(protected string $threadId)
    {
        parent::__construct($threadId);
    }

    protected function messageStore(): MessageStoreInterface
    {
        return new EloquentMessageStore(ChatMessage::class);
    }

    protected function contextWindow(): int
    {
        return 100000;
    }
}
```

Steps:

1. Replace `chatHistory()` with `messageStore()`, returning the store from the Case 1 table. Delete `chatHistory()` and its `ChatHistoryInterface` import in this step. Other uses of `ChatHistoryInterface` are migrated by guide 32.
2. Move a context window other than 50000 to `contextWindow()`. A hook that returned `new InMemoryChatHistory(...)` is deleted without a `messageStore()` override, because in-memory is the default, unless a parent class overrides `chatHistory()` or `messageStore()`: then override `messageStore()` here with `return new InMemoryMessageStore();` (`use NeuronAI\Chat\History\InMemoryMessageStore;`).
3. The thread the hook read (`$this->threadId` here) must be the Agent's thread ID. Guide 13 bound it, through the constructor, `make(workflowId:)`, `setThreadId()` or a `workflowId()` override. Keep properties that other code still uses.
4. A property named `$contextWindow` or `$messageStore` in the Agent or RAG subclass collides with the Agent's own `protected ?int $contextWindow` and `protected ?MessageStoreInterface $messageStore`; with another type it fails at class load (`Type of SupportAgent::$contextWindow must be ?int (as in class NeuronAI\Agent\Agent)`). Delete it when it only fed the history, and return its value from `contextWindow()` or build the store in `messageStore()`. Otherwise rename it and its uses.
5. Delete a `getChatHistory()` override. If it built a backend, move that backend to `messageStore()`, unless the class already has one. Callers of `$agent->getChatHistory()` keep working (Case 6).

### Case 4: `FileChatHistory`

Before (3.x):

```php
use NeuronAI\Chat\History\FileChatHistory;

$agent = SupportAgent::make(workflowId: $threadId)
    ->setChatHistory(new FileChatHistory('/var/app/storage', $threadId, prefix: 'chats/neuron_'));
```

After (4.x):

```php
use NeuronAI\Chat\History\FileMessageStore;

$agent = SupportAgent::make(workflowId: $threadId)
    ->setMessageStore(new FileMessageStore('/var/app/storage/chats', prefix: 'neuron_'));
```

- The 3.x `$key` is the Agent's thread ID (Case 1, step 3). An Agent bound to another value reads a different file.
- A `$prefix` or `$ext` that contains `/`, `\` or a NUL byte now throws `ChatHistoryException` in the constructor. Move the folder segments into `$directory`, as above. The file path stays the same.
- File names and permissions are covered by guide 30.

### Case 5: Container bindings

Before (3.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\EloquentChatHistory;

// in a service provider's register()
$this->app->bind(ChatHistoryInterface::class, fn () => new EloquentChatHistory(request('thread_id'), ChatMessage::class));

class SupportAgent extends Agent
{
    public function __construct(protected ChatHistoryInterface $history)
    {
        parent::__construct();
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return $this->history;
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;

// in a service provider's register()
$this->app->singleton(MessageStoreInterface::class, fn () => new EloquentMessageStore(ChatMessage::class));

class SupportAgent extends Agent
{
    public function __construct(protected MessageStoreInterface $historyStore)
    {
        parent::__construct();
    }

    protected function messageStore(): MessageStoreInterface
    {
        return $this->historyStore;
    }
}
```

- A store keeps no conversation state, so one instance serves every thread. Bind it without a thread ID. Guide 13 already binds the request's thread ID on the Agent (`for()`, `setThreadId()` or `make(workflowId:)`): check that it is the value the 3.x binding passed to the history.
- `EloquentMessageStore` resolves the model's connection on every call, so a singleton is safe. Give a `SQLMessageStore` the same lifetime as the PDO it receives.
- Instead of constructor injection, the Agent's own binding can call `->setMessageStore($app->make(MessageStoreInterface::class))`.
- Do not name the injected property `$messageStore` or `$contextWindow` (Case 3, step 4).
- In Symfony or other YAML/XML service definitions, apply the Case 1 table: drop the thread and window arguments.

### Case 6: A backend used directly, outside an Agent

Controllers, scripts, custom workflow nodes and tests that built a backend and called the history API on it.

Before (3.x):

```php
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Chat\Messages\UserMessage;

$history = new SQLChatHistory($threadId, $pdo, contextWindow: 100000);
$history->addMessage(new UserMessage('Imported question'));
$messages = $history->getMessages();
$history->flushAll();
```

After (4.x):

```php
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Chat\Messages\UserMessage;

$history = new ChatHistory(new SQLMessageStore($pdo), $threadId, 100000);
$history->addMessage(new UserMessage('Imported question'));
$messages = $history->getMessages();
$history->flushAll();
```

- `ChatHistory` takes the store, the thread ID and the context window (default 50000). The same applies to every backend: a test's `new InMemoryChatHistory()` becomes `new ChatHistory(new InMemoryMessageStore(), 'test-thread')`.
- Code that only reads can use the store directly. `$store->loadActive($threadId)` returns what `getMessages()` returned. `$store->loadAll($threadId)` also returns the messages that trimming archived, and `loadAll($threadId, limit: 50, before: $messageId)` pages backward. `$store->clear($threadId)` deletes the thread like `flushAll()`.
- For an Agent's own thread, use `$agent->getChatHistory()`. It returns a new object read from the store on every call, while 3.x returned the live object the Agent wrote into. Call it again after each run instead of reading an object you obtained before the run.

### Case 7: A custom backend that extends `AbstractChatHistory`

| 3.x `AbstractChatHistory` | 4.x `MessageStoreInterface` |
|---|---|
| constructor taking the thread ID, `$contextWindow` (and `$trimmer`) and calling `parent::__construct()` | constructor taking only the storage dependencies; a `$trimmer`: Case 9 |
| loading in the constructor into `$this->history` | `loadActive(string $threadId): array`: the thread's non-archived messages, in insertion order |
| `onNewMessage(Message $message)` | `append(string $threadId, Message $message): void`: return early when `$message->getId()` is already stored in the thread |
| `setMessages(array $messages)` | none: persist only through `append()` and `archive()` |
| `onTrimHistory(int $index)`, which deleted the oldest `$index` messages | `archive(string $threadId, int $count): void`: mark the oldest `$count` active messages archived and keep them |
| `clear()` | `clear(string $threadId): void`: delete the thread, archived messages included |
| none | `loadAll(string $threadId, ?int $limit = null, ?string $before = null): array`: every message, archived included, in insertion order; with `$limit`, the `$limit` newest messages before the message whose ID is `$before` (the latest when `null`); an unknown `$before` returns `[]` |
| `$this->deserializeMessages($rows)` | `(new MessageDeserializer())->deserialize($row)` for each row (`NeuronAI\Chat\Messages\MessageDeserializer`) |
| `$this->history`, `$this->contextWindow`, `$this->trimmer`, `trimHistory()`, `addMessage()`, `getMessages()`, `flushAll()` | delete: `ChatHistory` owns them |

Before (3.x):

```php
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\Message;

class RedisChatHistory extends AbstractChatHistory
{
    public function __construct(protected Redis $redis, protected string $threadId, int $contextWindow = 50000)
    {
        parent::__construct($contextWindow);

        $stored = array_map(fn (string $json): array => json_decode($json, true), $this->redis->lRange($this->key(), 0, -1));
        $this->history = $this->deserializeMessages($stored);
    }

    protected function onNewMessage(Message $message): void
    {
        $this->redis->rPush($this->key(), json_encode($message));
    }

    protected function onTrimHistory(int $index): void
    {
        $this->redis->lTrim($this->key(), $index, -1);
    }

    protected function clear(): void
    {
        $this->redis->del($this->key());
    }

    protected function key(): string
    {
        return "chat:{$this->threadId}";
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\History\PaginatesMessages;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;

class RedisMessageStore implements MessageStoreInterface
{
    use PaginatesMessages;

    public function __construct(protected Redis $redis)
    {
    }

    public function loadActive(string $threadId): array
    {
        return array_slice($this->loadAll($threadId), $this->archivedCount($threadId));
    }

    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        $deserializer = new MessageDeserializer();
        $messages = array_map(
            fn (string $json): Message => $deserializer->deserialize(json_decode($json, true)),
            $this->redis->lRange("chat:{$threadId}", 0, -1)
        );

        return $this->paginate($messages, $limit, $before);
    }

    public function append(string $threadId, Message $message): void
    {
        foreach ($this->loadAll($threadId) as $stored) {
            if ($stored->getId() === $message->getId()) {
                return;
            }
        }

        $this->redis->rPush("chat:{$threadId}", json_encode($message));
    }

    public function archive(string $threadId, int $count): void
    {
        $archived = min($this->archivedCount($threadId) + max(0, $count), $this->redis->lLen("chat:{$threadId}"));
        $this->redis->set("chat:{$threadId}:archived", $archived);
    }

    public function clear(string $threadId): void
    {
        $this->redis->del("chat:{$threadId}", "chat:{$threadId}:archived");
    }

    protected function archivedCount(string $threadId): int
    {
        return (int) $this->redis->get("chat:{$threadId}:archived");
    }
}
```

- Keep the 3.x storage keys and encoding, so the data stored with 3.x is found. Store `$message->jsonSerialize()` (here through `json_encode($message)`): it carries the message ID under `__id`, which `deserialize()` restores.
- A store that holds a thread as an ordered list can use the `PaginatesMessages` trait for `loadAll()`, as above.
- Overrides of the 3.x `deserializeMessage()`, `deserializeToolCall()`, `deserializeToolCallResult()`, `deserializeContent()`, `deserializeContentBlock()` or `deserializeMeta()` move to a subclass of `MessageDeserializer`, which has protected methods with the same names. Adapt their bodies to the 4.x message classes, and have the store instantiate that subclass.
- Register the store on the Agent with `setMessageStore()` or `messageStore()` (Cases 1 and 3), or pass it to `new ChatHistory($store, $threadId)` (Case 6).

### Case 8: A subclass of a built-in backend

Before (3.x):

```php
use NeuronAI\Chat\History\FileChatHistory;

class TenantChatHistory extends FileChatHistory
{
    public function __construct(protected string $tenant, string $directory, string $key)
    {
        parent::__construct($directory, $key);
    }

    protected function getFilePath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->tenant . '-' . $this->key . $this->ext;
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\History\FileMessageStore;

class TenantMessageStore extends FileMessageStore
{
    public function __construct(protected string $tenant, string $directory)
    {
        parent::__construct($directory);
    }

    protected function path(string $threadId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->tenant . '-' . $threadId . $this->ext;
    }
}
```

Steps:

1. Extend the matching store: `SQLMessageStore`, `EloquentMessageStore` or `FileMessageStore`. A subclass of `InMemoryChatHistory`, which had no storage of its own, is a custom backend: Case 7. Renaming the class is optional; if you rename it, update its references.
2. Constructor: drop the thread and window parameters, and call `parent::__construct()` with the Case 1 arguments. Its constructions change as in Case 1.
3. `$this->thread_id` (SQL), `$this->threadId` (Eloquent) and `$this->key` (File) become the `$threadId` parameter of each store method. `$this->pdo`, `$this->table`, `$this->modelClass`, `$this->directory`, `$this->prefix` and `$this->ext` keep their names.
4. `FileChatHistory::getFilePath(): string` becomes `FileMessageStore::path(string $threadId): string`. Keep the 3.x file-name formula, as above, so existing files are found.
5. `EloquentChatHistory::recordToArray(Model $record): array` and `serializeMessageMeta(Message $message): array` keep their names and signatures. A `recordToArray()` override must start from `parent::recordToArray($record)`, which puts the `message_id` column under `__id`.
6. `load()`, `setMessages()`, `onNewMessage()`, `onTrimHistory()`, `clear()`, `updateFile()`, `sanitizeTableName()` and `tableExists()` have no counterpart. Move their logic into overrides of `loadActive()`, `append()`, `archive()` or `clear()` (taking `$threadId` and calling the parent), or delete it.

### Case 9: A custom trimmer

In 3.x a trimmer could be passed to `InMemoryChatHistory` or to an `AbstractChatHistory` subclass. The 4.x Agent builds its history with the default `HistoryTrimmer`. Apply this case only when the 3.x code passed its own trimmer: override `resources()` so that the run uses it.

Before (3.x):

```php
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;

protected function chatHistory(): ChatHistoryInterface
{
    return new InMemoryChatHistory(100000, new KeepLastTurnsTrimmer());
}
```

After (4.x):

```php
use NeuronAI\Agent\AgentResources;
use NeuronAI\Chat\History\ChatHistory;

protected function contextWindow(): int
{
    return 100000;
}

protected function resources(): AgentResources
{
    $resources = parent::resources();

    return new AgentResources(
        $resources->provider,
        new ChatHistory(
            $this->resolveMessageStore(),
            $resources->history->getThreadId(),
            $this->contextWindow ?? $this->contextWindow(),
            new KeepLastTurnsTrimmer(),
            $this->historyTrimRatio ?? $this->historyTrimRatio(),
        ),
        $resources->instructions,
        $resources->tools,
    );
}
```

- `KeepLastTurnsTrimmer` stands for the application's own trimmer class. Create a new instance for every history, as above.
- When the trimmer was passed at a call site (`setChatHistory(new InMemoryChatHistory(100000, new KeepLastTurnsTrimmer()))`), add the override to the Agent class used there. If that class is `Agent` itself, or is also used without the trimmer, ask the developer whether to create a dedicated subclass.
- The store still comes from `setMessageStore()` or `messageStore()`, the window from `setContextWindow()` or `contextWindow()`, and the trim ratio from `setHistoryTrimRatio()` or `historyTrimRatio()` (guide 32, Case 9).
- `$agent->getChatHistory()` outside a run keeps the default trimmer.
- A trimmer passed to a history used outside an Agent (Case 6) becomes the fourth argument: `new ChatHistory($store, $threadId, $contextWindow, new KeepLastTurnsTrimmer())`.
- What a custom trimmer must do in 4.x is covered by guide 32.

### Case 10: Message classes that declare `getId()` or `setId()`

4.x `Message` declares `getId(): string` and `setId(string $id): static`: the message identity that stores and `ChatHistory` de-duplicate on. In a class that extends a Neuron message class, an own `getId()` or `setId()` with an incompatible signature is a fatal error at class load. A compatible one silently replaces the message identity. Rename them and update their callers.

Before (3.x):

```php
use NeuronAI\Chat\Messages\UserMessage;

class TicketMessage extends UserMessage
{
    protected ?int $ticketId = null;

    public function getId(): ?int
    {
        return $this->ticketId;
    }

    public function setId(int $id): self
    {
        $this->ticketId = $id;
        return $this;
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\UserMessage;

class TicketMessage extends UserMessage
{
    protected ?int $ticketId = null;

    public function getTicketId(): ?int
    {
        return $this->ticketId;
    }

    public function setTicketId(int $id): self
    {
        $this->ticketId = $id;
        return $this;
    }
}
```

`$message->getMetadata('__id')` and `addMetadata('__id', $id)` keep working. `getId()` and `setId()` are the 4.x accessors for the same value, and no change is required.

## Checklist

- Searches 1 and 2 find nothing, except in `AgentInterface` implementers left for guide 57 and in the calls, hooks and bindings that use an application class implementing `ChatHistoryInterface` directly (guide 32). No hit of search 3 is in a message store, and no hit of search 4 is in an Agent or RAG class.
- Every `SQLMessageStore` reads the per-message table from guide 30 (`chat_messages` when no table is passed), never the 3.x table.
- No Agent or RAG class defines `chatHistory()` or `getChatHistory()`, except the hooks left for guide 32 Case 3.
- Every Agent that had a history is bound to the thread ID that history used (file: `$key`), and keeps its context window through `setContextWindow()` or `contextWindow()` when it was not 50000.
- Histories filled in tests or seeders are written through `$agent->getChatHistory()` on the right store and thread.
- Custom stores implement the five `MessageStoreInterface` methods. `append()` skips an ID already stored in the thread, and `archive()` keeps the messages.
- An Agent whose 3.x history had a custom trimmer overrides `resources()` as in Case 9.
- Container bindings hold a `MessageStoreInterface` and read no thread ID.
- No message subclass declares its own `getId()` or `setId()`.
- The application's tests and static analysis report no error about the removed history classes or methods.
