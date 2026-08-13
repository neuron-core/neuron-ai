# Upgrade: the unified workflow store & thread-first continuation

## Summary

Workflow persistence is redesigned around one idea: **the store is a single
partitioned key-value space** — string values filed under `(partition, key)`.
A run's records (ignition, steps, memos) live in the partition named by its
**workflow ID** — the business key the workflow declares (the Agent declares its
threadId; an order workflow might declare `'order:123'`), or an
engine-generated handle when it declares none. One artifact per backend —
one table, one directory, one array — forever, for every current and future
record type. There are no engine indexes and no reserved partitions: the
business key IS the storage location, so a `resume()` built from the key
alone finds the pending run with a single read, and thread → run resolution
moves out of the chat-history tail.

What changed:

1. **`PersistenceInterface` is now three methods**:

   ```php
   public function put(string $partition, string $key, string $value): void;   // write-or-overwrite
   public function get(string $partition, string $key): ?string;
   public function delete(string $partition): void;                            // drop a whole partition
   ```

   Backends store opaque strings and know nothing about workflows. Custom
   backends must be rewritten — see the adapter example below.
2. **The engine owns serialization.** Backends no longer take a `Serializer`
   constructor parameter; the codec is a workflow-owned seam:
   `$workflow->setSerializer(new IgbinarySerializer())` (default
   `PhpSerializer`). The `Serializer` interface is now
   `serialize(mixed): string` / `unserialize(string): mixed`.
3. **One table replaces two.** `workflow_steps` becomes `workflow_store`
   (DDL below); the `workflow_correlations` table from the 4.x development
   branch is gone (it never shipped in a release).
4. **The workflow ID is the identity; one live run per workflow ID.** A workflow
   declares its business key by overriding `workflowId(): ?string`; records live
   under it, and `run()` while a run is already in flight for the workflow ID
   throws ("run is already in flight") — settle the pending run by resuming it. Clean
   completion sweeps the whole partition, so nothing ever leaks. A per-run
   **runId** (generation stamp) lives inside the ignition record for
   observability and write-fencing (step keys are runId-prefixed) — it is
   never the continuation handle.
5. **The runId is gone from chat history.**
   `ToolCallMessage::setRunId()/getRunId()/setResumeToken()/getResumeToken()`
   are removed. Chat history is conversation only.
6. **`Workflow::make(?string $workflowId)` and `getWorkflowId(): ?string`** — the
   constructor parameter and continuation handle are the workflow ID.
   `getRunId(): ?string` still exists but now returns the generation stamp.
   Both are null before the first run segment; identity is assigned by the
   executor, never defaulted at construction.
7. **A continuation must identify a run.** `resume()` on a workflow with no
   explicit and no declared workflow ID throws a `WorkflowException`. `resume()`
   also gains revive semantics: a **null** payload (the new default) replays
   without delivering anything (crash recovery), while `[]` delivers an
   explicitly empty answer.
8. **`FilePersistence`** now writes one file per partition
   (`<partition>.store`, name URL-encoded for filesystem safety) instead of
   `<runId>.workflow`, and throws on a failed write.

## Update your code

The approve/deny endpoint keeps its promise — the thread is the only handle
you need:

```php
// Identical before and after — and it now works for EVERY suspension type
// (approval, awaitEvent, sleepUntil), not just approvals:
$agent = Agent::make()
    ->setChatHistory(new SQLChatHistory($threadId, $pdo))
    ->setPersistence($persistence);

$agent->resume(['call_123' => 'approve']);
```

If your UI read the runId off the tail message, drop it — rendering pending
approvals never needed it (read the tool calls' approval states and reasons),
and the approve endpoint posts back the threadId.

Backend construction loses the serializer parameter; the codec moves to the
workflow:

```php
// Before
$persistence = new DatabasePersistence($pdo, 'workflow_steps', new IgbinarySerializer());

// After
$persistence = new DatabasePersistence($pdo);            // table: workflow_store
$workflow->setPersistence($persistence)
    ->setSerializer(new IgbinarySerializer());           // optional; default PhpSerializer
```

A custom backend shrinks to a trivial adapter:

```php
// Before: save(runId, stepId, StepResult) / load(...): ?StepResult / delete(runId)
// After:
class RedisPersistence implements PersistenceInterface
{
    public function __construct(protected Redis $redis) {}

    public function put(string $partition, string $key, string $value): void
    {
        $this->redis->hSet($partition, $key, $value);
    }

    public function get(string $partition, string $key): ?string
    {
        $value = $this->redis->hGet($partition, $key);
        return $value === false ? null : $value;
    }

    public function delete(string $partition): void
    {
        $this->redis->del($partition);
    }
}
```

Your own workflows opt into key-first continuation by declaring their workflow ID:

```php
class OrderWorkflow extends Workflow
{
    public function workflowId(): ?string
    {
        return 'order:' . $this->orderId;
    }
}

// Later, in a blank process holding only the order id:
OrderWorkflow::make(orderId: $orderId)->setPersistence($persistence)->resume($payload);
```

## Required migration

`DatabasePersistence` / `EloquentPersistence` users create the new table
(`partition` and `key` are reserved words in MySQL — quote them):

```sql
-- ANSI (PostgreSQL, SQLite)
CREATE TABLE workflow_store (
    "partition" VARCHAR(255) NOT NULL,
    "key"       VARCHAR(255) NOT NULL,
    "value"     TEXT NOT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY ("partition", "key")
);

-- MySQL / MariaDB
CREATE TABLE workflow_store (
    `partition` VARCHAR(255) NOT NULL,
    `key`       VARCHAR(255) NOT NULL,
    `value`     TEXT NOT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`partition`, `key`)
);
```

Existing `workflow_steps` data is shape-identical (`run_id`→`partition`,
`step_id`→`key`, `result`→`value`), so in-flight runs can survive via a
rename migration — but note the record-format change below before relying on
that.

`EloquentPersistence` now takes only the model class; the model needs
`partition`, `key`, and `value` string columns.

## Drain pending runs before upgrading

Two reasons runs suspended by the previous version cannot be resumed after
upgrading:

- their ignition/memo records were stored wrapped in `StepResult`; the new
  engine reads them serialized directly, and
- their records live in a runId-named partition with unprefixed step keys;
  the new engine reads partitions named by the workflow ID with
  generation-prefixed keys.

Drain pending approvals/suspensions before deploying, or complete them on the
old version. (This is a development-branch-to-development-branch note; no
released version wrote the old layout.)

## API removals

- `ToolCallMessage::setRunId()`, `getRunId()`, `setResumeToken()`, `getResumeToken()`
- `CorrelationStoreInterface` (never released)
- `PersistenceInterface::save()/load()` — replaced by `put()/get()`
- Backend `Serializer` constructor parameters — replaced by `Workflow::setSerializer()`

## What to search for

```
grep -rn "->save(\|->load(\|new DatabasePersistence\|new EloquentPersistence\|new FilePersistence\|getRunId\|getResumeToken" --include="*.php" .
```

Check each hit: persistence calls get the new verbs; backend constructions
lose serializer arguments; a `getRunId()` used as a resume handle becomes
`getWorkflowId()` (`getRunId()` still exists but returns the generation stamp);
the `ToolCallMessage` variants are gone.
