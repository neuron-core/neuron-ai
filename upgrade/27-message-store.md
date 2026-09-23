# Upgrade: Conversations are stored through a message store

## Summary

Chat history is split in two:

- A **message store** (`MessageStoreInterface`) persists conversations. It is stateless and every call
  names its thread, so one instance serves the whole application: bind it once in your container.
- A **working history** (`ChatHistory`) is the context the model sees during one execution segment.
  The Agent builds a new one over the store at the start of every segment, for its own thread.
  Guide 28 covers the removal of `ChatHistoryInterface`, which `ChatHistory` replaces.

The storage backends are renamed and lose their thread and context window arguments, and the Agent
receives a store instead of a history.

| Before | After |
|---|---|
| `new SQLChatHistory($pdo, $threadId, 'chat_messages', 50000)` | `new SQLMessageStore($pdo, 'chat_messages')` |
| `new EloquentChatHistory(ChatMessage::class, $threadId, 50000)` | `new EloquentMessageStore(ChatMessage::class)` |
| `new FileChatHistory($directory, $key, 50000, 'neuron_', '.chat')` | `new FileMessageStore($directory, 'neuron_', '.chat')` |
| `new InMemoryChatHistory($threadId, 50000)` | `new InMemoryMessageStore()` |
| `$agent->setChatHistory($history)` | `$agent->setMessageStore($store)` |
| `chatHistory(string $threadId)` hook returning a backend | `messageStore()` hook returning a store |
| Context window passed to the backend constructor | `contextWindow()` hook or `setContextWindow()` on the Agent |
| `class MyHistory extends AbstractChatHistory` | `class MyStore implements MessageStoreInterface` |
| `$message->getMetadata('__id')` | `$message->getId()` |

All classes stay in `NeuronAI\Chat\History`.

Behavior that changes with the split:

- **Every execution segment loads the conversation from the store.** An Agent kept alive across
  requests (Octane, queue workers) sees the messages other workers added in the meantime.
- **`getChatHistory()` returns a fresh view on every call.** Read through it outside executions;
  writing through it while an execution runs is unsupported.
- **Appending is idempotent per thread.** A message whose ID is already stored in the thread is
  skipped, so a replayed history write can no longer duplicate rows.
- **Constructing a store performs no I/O.** `SQLMessageStore` validates only the table name format
  (it no longer queries the table's existence); `FileMessageStore` creates its directory on first write.
- **`FileMessageStore` writes files atomically**, through a temporary file readable by its owner only,
  and URL-encodes the thread ID in the file name. IDs made of letters, digits, `-`, `_`, `.` and `~`
  keep their existing file names.
- **Full transcripts are readable.** `loadAll($threadId, $limit, $before)` returns every message of
  the thread, archived ones included, optionally one page before a message ID.

## 1. Add `message_id` to your messages table

Every stored message now carries its identity in a `message_id` column, unique within its thread.

- **`SQLMessageStore`**: tables created with guide 6 already have it.
- **`EloquentMessageStore`**: tables created for 3.x need the column. Add `message_id` to the model's
  `$fillable`, then backfill it from the identity stored in `meta` with a migration:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('message_id', 64)->nullable()->after('thread_id');
        });

        $seen = [];
        DB::table('chat_messages')->orderBy('id')->chunkById(500, function ($rows) use (&$seen) {
            foreach ($rows as $row) {
                $id = json_decode($row->meta ?? '{}', true)['__id'] ?? "legacy_{$row->id}";

                // Rows are never deleted: a repeated identity within a thread gets a suffix.
                if (isset($seen[$row->thread_id][$id])) {
                    $id = "{$id}_{$row->id}";
                }
                $seen[$row->thread_id][$id] = true;

                DB::table('chat_messages')->where('id', $row->id)->update(['message_id' => $id]);
            }
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('message_id', 64)->nullable(false)->change();
            $table->unique(['thread_id', 'message_id']);
        });
    }
};
```

The model key orders each thread, so it must follow insertion order: an auto-increment ID, a ULID or
a UUIDv7, not a UUIDv4.

File stores need no migration: entries written without an identity receive one on the thread's next
write.

## 2. Configure a store instead of a history

### Case 1: The setter

Before:

```php
$agent->setChatHistory(new SQLChatHistory($pdo));
```

After:

```php
use NeuronAI\Chat\History\SQLMessageStore;

$agent->setMessageStore(new SQLMessageStore($pdo));
```

A history constructed with a thread ID also declared the Agent's identity. Declare it on the Agent:

Before:

```php
$agent = SupportAgent::make()->setChatHistory(new SQLChatHistory($pdo, $threadId));
```

After:

```php
$agent = SupportAgent::make(workflowId: $threadId)->setMessageStore(new SQLMessageStore($pdo));
```

### Case 2: The hook

Before:

```php
protected function chatHistory(string $threadId): ChatHistoryInterface
{
    return new EloquentChatHistory(ChatMessage::class, contextWindow: 100000);
}
```

After:

```php
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;

protected function messageStore(): MessageStoreInterface
{
    return new EloquentMessageStore(ChatMessage::class);
}

protected function contextWindow(): int
{
    return 100000;
}
```

The context window defaults to 50,000 tokens (`ChatHistory::DEFAULT_CONTEXT_WINDOW`). An Agent
configured from outside can call `setContextWindow()` instead of overriding the hook.

Move the store of a `chatHistory()` override to `messageStore()` and its context window to
`contextWindow()`; guide 28 removes the hook itself.

### Case 3: Container bindings

The store is safe to share, so bind it once and resolve Agents as before:

```php
$this->app->singleton(MessageStoreInterface::class, fn () => new EloquentMessageStore(ChatMessage::class));

$this->app->bind(SupportAgent::class, fn ($app) => (new SupportAgent())
    ->setMessageStore($app->make(MessageStoreInterface::class))
    ->setContextWindow(config('ai.context_window')));

// Per request or job: identity is the only per-conversation input.
$agent = app(SupportAgent::class)->setThreadId($threadId);
```

### Case 4: A custom backend

A backend no longer extends `AbstractChatHistory`: it implements `MessageStoreInterface`, whose
methods are the former hooks with the thread as a parameter.

| `AbstractChatHistory` hook | `MessageStoreInterface` method |
|---|---|
| `loadThread()` | `loadActive(string $threadId): array` |
| `onNewMessage(Message $message)` | `append(string $threadId, Message $message)`: skip a message whose `getId()` is already stored in the thread |
| `onTrimHistory(int $index)` | `archive(string $threadId, int $count)`: archive the oldest `$count` active messages |
| `clear()` | `clear(string $threadId)` |
| — | `loadAll(string $threadId, ?int $limit = null, ?string $before = null)`: every message, archived included |

Rebuild stored messages with `NeuronAI\Chat\Messages\MessageDeserializer::deserialize()`, which
replaces the protected `deserializeMessages()` family. Restore the stored identity by passing it as
the `__id` key of the array.

### Case 5: Reading messages for a UI

`$agent->getChatHistory()->getMessages()` still returns the model context: the messages not
archived by trimming. To render a conversation, read the transcript from the store and use each
message's ID as its stable key and page cursor:

```php
$page = $store->loadAll($threadId, limit: 50);
$older = $store->loadAll($threadId, limit: 50, before: $page[0]->getId());
```

## What to Search For

```
grep -rnE "(InMemory|SQL|File|Eloquent|Abstract)ChatHistory" --include="*.php" .
grep -rn "setChatHistory(" --include="*.php" .
grep -rn "function chatHistory(" --include="*.php" .
grep -rn "getMetadata('__id')" --include="*.php" .
```

## Checklist

- No reference to the removed history classes or to `setChatHistory()` remains.
- Every messages table has `message_id` with a unique `(thread_id, message_id)` index.
- An Agent that relied on a pre-bound history for its identity now receives it through
  `make(workflowId:)` or `setThreadId()`.
- A shared container binding holds a message store, never a `ChatHistory`.
