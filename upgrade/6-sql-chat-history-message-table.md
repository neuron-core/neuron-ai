# Upgrade: SQLChatHistory — one row per message

## Summary

`SQLChatHistory` no longer stores the whole conversation as a JSON blob in a single
`messages` column. It now stores **one row per message associated to a `thread_id`**,
the same structure already used by `EloquentChatHistory`. This makes the chat history
easier to integrate with external architectures (ORMs, reporting, per-message stats,
data retention policies) and avoids rewriting the entire thread on every message.

This is a breaking change to two areas:

1. **The table schema changed** — a new table with `role`, `content`, and `meta` columns.
2. **The default table name changed** — from `chat_history` to `chat_messages`, so the
   new structure never collides with the legacy table.

## 1. Create the new table

MySQL:

```sql
CREATE TABLE chat_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL,
    content LONGTEXT NULL,
    meta LONGTEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_thread_id (thread_id)
);
```

PostgreSQL:

```sql
CREATE TABLE chat_messages (
    id BIGSERIAL PRIMARY KEY,
    thread_id VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL,
    content TEXT NULL,
    meta TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_chat_messages_thread_id ON chat_messages (thread_id);
```

SQLite:

```sql
CREATE TABLE chat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    thread_id TEXT NOT NULL,
    role TEXT NOT NULL,
    content TEXT NULL,
    meta TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_chat_messages_thread_id ON chat_messages (thread_id);
```

Columns:

- `role` — the message role (`user`, `assistant`, `system`, `tool`).
- `content` — the message content blocks, JSON encoded (`NULL` when the message has no content).
- `meta` — everything else carried by the message (usage, tool calls/results, custom metadata), JSON encoded.

## 2. Migrate the existing messages

Run the provided `SQLChatHistoryMigration` once in your application (a deploy script,
a console command, an artisan command, etc.). It reads every thread from the legacy
table, splits the JSON blob into one row per message, and writes them into the new table:

```php


$migration = new SQLChatHistoryMigration(
    pdo: $pdo,
    from: 'chat_history',   // legacy single-column table (default)
    to: 'chat_messages',    // new per-message table (default)
);

$migratedMessages = $migration->run();
```

Properties of the migration:

- **Transactional** — runs inside a single transaction; on failure everything is rolled back.
- **Idempotent** — threads that already have rows in the target table are skipped,
  so it is safe to run it again.
- **Non-destructive** — the legacy table is left untouched. Drop it once you verified
  the migration:

```sql
DROP TABLE chat_history;
```

## 3. Update your code

If you relied on the default table name, no code change is needed — `SQLChatHistory`
now defaults to `chat_messages`:

```php
$history = new SQLChatHistory($threadId, $pdo);
```

If you passed a custom table name, point it to the new per-message table:

```php
$history = new SQLChatHistory($threadId, $pdo, table: 'my_chat_messages');
```

Note: `SQLChatHistory` no longer creates an empty row when a new thread starts —
a thread with no messages simply has no rows.

## What to search for

```
grep -rn "SQLChatHistory" --include="*.php" .
```
