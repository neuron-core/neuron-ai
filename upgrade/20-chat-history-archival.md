# Upgrade: Trimmed chat messages are archived, not deleted

## Summary

`SQLChatHistory`, `EloquentChatHistory` and `FileChatHistory` no longer delete the
messages trimmed out of the context window. A trim marks them with an `archived_at`
timestamp and leaves them in place, and the history loads only the unarchived
messages. The full transcript of a thread stays available to your application
(auditing, analytics, retention policies) while the model keeps seeing the trimmed
thread.

`flushAll()` is unchanged: it still removes the whole thread, archived messages
included. `InMemoryChatHistory` is unchanged as well.

This is a breaking change for the two database backends: the `chat_messages` table
needs a nullable `archived_at` column, and both backends filter on it when loading.

## 1. Add the `archived_at` column

MySQL:

```sql
ALTER TABLE chat_messages ADD COLUMN archived_at DATETIME NULL AFTER meta;
```

PostgreSQL:

```sql
ALTER TABLE chat_messages ADD COLUMN archived_at TIMESTAMP NULL;
```

SQLite:

```sql
ALTER TABLE chat_messages ADD COLUMN archived_at TEXT NULL;
```

Laravel migration (Eloquent):

```php
Schema::table('chat_messages', function (Blueprint $table) {
    $table->timestamp('archived_at')->nullable()->after('meta');
});
```

`SQLChatHistory` archives with the database `CURRENT_TIMESTAMP`; `EloquentChatHistory`
writes the model's fresh timestamp, so the column follows your model's date format.

## 2. File histories

Nothing to migrate. Archived entries are written to the same `.chat` file with an
`archived_at` key (ISO 8601). Files written before this change contain only
unarchived entries and load as before.

## 3. Custom backends

If you extend `AbstractChatHistory`, the trim hook now receives the trimmed messages
(oldest first) instead of their count:

Before:

```php
protected function onTrimHistory(int $index): void
```

After:

```php
/**
 * @param Message[] $messages
 */
protected function onTrimHistory(array $messages): void
```

## What to search for

```
grep -rn "onTrimHistory" --include="*.php" .
grep -rn "chat_messages" --include="*.php" --include="*.sql" .
```
