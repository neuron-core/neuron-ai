# Upgrade: Workflow persistence: backends store runs in one partitioned store

## Summary

In 3.x a persistence backend saved one serialized `WorkflowInterrupt` per workflow ID, and only when a run paused. In 4.x a backend is a key-value store the workflow engine writes during every run, filed under the workflow ID. The codec is set on the workflow, not on the backend. The SQL table, the Eloquent table and the file layout are all new.

| | 3.x | 4.x |
|---|---|---|
| `PersistenceInterface` | `save()`, `load()`, `delete()` of a `WorkflowInterrupt` | `get()`, `initializeIfAbsent()`, `writeIfUnchanged()`, `deleteIfUnchanged()` on opaque strings |
| Codec | `SerializablePersistenceInterface::serialize()/unserialize()` on each backend | Removed. Use a `Serializer` set with `setSerializer()` or the `serializer()` hook |
| `DatabasePersistence` | `($pdo, $table = 'workflow_interrupts')`, any PDO error mode | `($pdo, $table = 'workflow_store')`, new schema, requires `PDO::ERRMODE_EXCEPTION` and MySQL strict SQL mode |
| `EloquentPersistence` | model over `workflow_id`, `interrupt` | model over `partition`, `key`, `value` plus a unique (`partition`, `key`) |
| `FilePersistence` | `($directory, $prefix, $ext)`, throws if the directory is missing, writes `neuron_workflow_<id>.store` | `($directory)`, creates the directory on the first write, writes `<rawurlencode(id)>.store` |
| `InMemoryPersistence` | unchanged | unchanged |
| Read / discard a paused run | `$persistence->load($id)` / `$persistence->delete($id)` | `$workflow->inspect()` / `$workflow->abandon()` |

Neither SQL backend creates its table: if the app uses `DatabasePersistence` or `EloquentPersistence`, it needs a migration.

Stored data: 4.x reads nothing that 3.x stored. That covers `workflow_interrupts` rows, the old Eloquent table, `neuron_workflow_*.store` files and custom-backend records. Runs paused under 3.x, including pending agent tool approvals, cannot be resumed after the upgrade (Case 7).

Related guides:
- Guide 13 (already applied) bound a workflow ID to every workflow and agent, and moved persistence to `setPersistence()` or the `persistence()` hook.
- Guide 18 makes state and events serializable, because every run now writes them through the serializer.
- Guide 15 (workflows) and guide 29 (agent approvals) migrate how a paused run is continued.

## What to Search For

Run from the application root:

```bash
# 1. Backends, custom implementations, container definitions
grep -rnE 'PersistenceInterface|(Database|Eloquent|File|InMemory)Persistence' --include='*.php' --include='*.yaml' --include='*.yml' --include='*.xml' --exclude-dir=vendor .
# 2. Direct calls on a persistence object
grep -rnE '[pP]ersistence[^;]*->(load|save|delete|serialize|unserialize)[(]' --include='*.php' --exclude-dir=vendor .
# 3. Code matching the 3.x "nothing saved" error
grep -rn 'No saved workflow found' --include='*.php' --exclude-dir=vendor .
# 4. 3.x table and file names (migrations, raw SQL, config, cleanup jobs)
grep -rnIE 'workflow_interrupts([^A-Za-z0-9_]|$)|neuron_workflow_' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .
# 5. PDO error mode
grep -rnE 'ATTR_ERRMODE|ERRMODE_(SILENT|WARNING)' --include='*.php' --exclude-dir=vendor .
# 6. MySQL/MariaDB SQL mode
grep -rnE "'strict'|'modes'|sql_mode|INIT_COMMAND" --include='*.php' --include='*.yaml' --include='*.yml' --exclude-dir=vendor .
```

Follow the hits:
- Open every class that implements `PersistenceInterface` or `SerializablePersistenceInterface`, or extends a built-in backend. Look for `save()`, `load()`, `delete()`, `serialize()`, `unserialize()` and `getFilePath()`.
- Trace each persistence object to where it is built: constructors, `persistence()` hooks, service providers, `services.yaml`. Then trace it to every place it is used; the variable may have another name.
- Trace each PDO passed to `DatabasePersistence` back to where it is created. For each `EloquentPersistence`, find its model class and the model's connection.
- Searches 5 and 6 matter only for connections used by `DatabasePersistence` or `EloquentPersistence`. Search 6 matters only on MySQL/MariaDB.

If searches 1 to 4 find nothing, this guide does not apply.

## How to Refactor

### Case 1: `DatabasePersistence`

1. Generate a migration in the application's own migration system that creates the new table. Leave the existing migrations as they are, and do not run DDL yourself. Reference DDL:

   ```sql
   -- PostgreSQL / SQLite
   CREATE TABLE workflow_store (
       "partition" VARCHAR(510) NOT NULL,
       "key"       VARCHAR(510) NOT NULL,
       "value"     TEXT NOT NULL,
       updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY ("partition", "key")
   );

   -- MySQL / MariaDB
   CREATE TABLE workflow_store (
       `partition` VARCHAR(510) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
       `key`       VARCHAR(510) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
       `value`     LONGTEXT CHARACTER SET ascii NOT NULL,
       updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (`partition`, `key`)
   ) ENGINE=InnoDB;
   ```

   The framework writes only these four columns, so any extra column (such as `created_at`) needs a default. Laravel:

   ```php
   use Illuminate\Database\Migrations\Migration;
   use Illuminate\Database\Schema\Blueprint;
   use Illuminate\Support\Facades\Schema;

   return new class extends Migration
   {
       public function up(): void
       {
           // MySQL/MariaDB only: identifiers must compare byte by byte (PostgreSQL refuses ascii_bin).
           $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

           Schema::create('workflow_store', function (Blueprint $table) use ($mysql) {
               if ($mysql) {
                   $table->string('partition', 510)->charset('ascii')->collation('ascii_bin');
                   $table->string('key', 510)->charset('ascii')->collation('ascii_bin');
                   $table->longText('value')->charset('ascii');
               } else {
                   $table->string('partition', 510);
                   $table->string('key', 510);
                   $table->longText('value');
               }
               $table->timestamp('updated_at')->useCurrent();
               $table->primary(['partition', 'key']);
           });
       }

       public function down(): void
       {
           Schema::dropIfExists('workflow_store');
       }
   };
   ```

2. Remove an explicit `'workflow_interrupts'` table argument, because the default is now `workflow_store`. If the app passed its own table name, create the new table under a new name and pass that name: the old table keeps the 3.x columns. A schema-qualified name such as `'app.workflow_store'` is now quoted as one identifier. Select the schema on the connection instead (`search_path` or the database name) and pass an unqualified name.
3. The PDO must use `PDO::ERRMODE_EXCEPTION`. Otherwise the constructor throws `NeuronAI\Exceptions\PersistenceException`. Exception mode is the PHP 8 default and what Laravel and Doctrine connections use.
   - If the PDO exists only for the persistence, remove the `ERRMODE_SILENT` or `ERRMODE_WARNING` option, including the numeric forms `0` and `1`.
   - If it is a shared connection that other code relies on in silent or warning mode, do not change it. Recommend a dedicated PDO for the persistence to the developer, and mention that workflow writes then no longer join transactions the app opens on the shared connection.
4. On MySQL/MariaDB, the session `sql_mode` must contain `STRICT_TRANS_TABLES` or `STRICT_ALL_TABLES`. Otherwise the first write throws `PersistenceException` with "Workflow persistence requires MySQL strict SQL mode to prevent truncated records." 3.x had no such check.
   - Search 6 shows strict mode is off when it finds Laravel `'strict' => false`, a `'modes'` list without `STRICT_*`, or an init command or DSN that sets `sql_mode`.
   - This is an infrastructure decision. Report it to the developer with the two options: enable strict mode on that connection, or give the persistence its own strict connection. Do not edit the connection configuration.
5. On both SQL backends a workflow ID (an agent's thread ID) over 255 bytes throws `PersistenceException`. Only IDs with multibyte characters reach that limit: the engine already refuses IDs over 255 characters on every backend (guide 13).

Before (3.x):

```php
use NeuronAI\Workflow\Persistence\DatabasePersistence;

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
$persistence = new DatabasePersistence($pdo, 'workflow_interrupts');
```

After (4.x):

```php
use NeuronAI\Workflow\Persistence\DatabasePersistence;

$persistence = new DatabasePersistence(new PDO($dsn, $user, $password)); // table workflow_store
```

### Case 2: `EloquentPersistence`

The call `new EloquentPersistence(WorkflowRecord::class)` does not change, but the model's table does.

1. Generate a migration for the new table. It needs its own primary key, `partition` and `key` (VARCHAR 510), `value` (LONGTEXT on MySQL) and a unique (`partition`, `key`):

   ```php
   use Illuminate\Database\Migrations\Migration;
   use Illuminate\Database\Schema\Blueprint;
   use Illuminate\Support\Facades\Schema;

   return new class extends Migration
   {
       public function up(): void
       {
           $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

           Schema::create('workflow_store', function (Blueprint $table) use ($mysql) {
               $table->id();
               if ($mysql) {
                   $table->string('partition', 510)->charset('ascii')->collation('ascii_bin');
                   $table->string('key', 510)->charset('ascii')->collation('ascii_bin');
                   $table->longText('value')->charset('ascii');
               } else {
                   $table->string('partition', 510);
                   $table->string('key', 510);
                   $table->longText('value');
               }
               $table->timestamps();
               $table->unique(['partition', 'key']);
           });
       }

       public function down(): void
       {
           Schema::dropIfExists('workflow_store');
       }
   };
   ```

2. Point the model at the new table:
   - Set `protected $table = 'workflow_store';`.
   - Set `$fillable = ['partition', 'key', 'value']`, or `$guarded = []`.
   - Remove `SoftDeletes`, and any cast, accessor or mutator on these three columns.
   - If the table has no timestamp columns, set `public $timestamps = false;`.
3. Remove app code that reads the model's `workflow_id` or `interrupt` attributes. Case 6 replaces reads of paused runs.
4. `EloquentPersistence` needs `illuminate/database` (or `laravel/framework`) 10.21 or later. Guide 1, Case 2, already handled that floor.
5. Strict SQL mode is checked on the model's MySQL/MariaDB connection on every write. Report it as in Case 1, step 4. A dedicated connection is set as the model's `$connection`.

Before (3.x):

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowRecord extends Model
{
    protected $table = 'workflow_interrupts';

    protected $fillable = ['workflow_id', 'interrupt'];
}
```

After (4.x):

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowRecord extends Model
{
    protected $table = 'workflow_store';

    protected $fillable = ['partition', 'key', 'value'];
}
```

### Case 3: `FilePersistence`

1. Remove the second and third constructor arguments, including in container definitions. Named `prefix:` and `ext:` arguments fail with "Unknown named parameter", and positional ones are silently ignored. Files are now named `<rawurlencode(workflowId)>.store`.
2. The constructor no longer throws `WorkflowException` for a missing directory. The directory is created on the first write, and read or write failures throw `PersistenceException` at that call.
   - Remove the `try`/`catch` blocks and the tests that expect the constructor exception.
   - Where the app must fail fast on a misconfigured directory, check `is_dir()` before constructing.
3. A subclass overriding `protected function getFilePath(string $workflowId): string` must override `protected function filePath(string $partition): string` instead. The partition is the workflow ID.
   - `$this->prefix` and `$this->ext` no longer exist.
   - Return a file name that is unique per workflow ID and safe for the filesystem, because IDs may contain `:` or `/`.
4. 4.x reads a 3.x file only when a 4.x file has the same name. This happens when 3.x used an empty prefix with the `.store` extension, or when a migrated `filePath()` keeps the 3.x naming, as `HashedFilePersistence` below does. Then `inspect()` and even a new `run()` for that workflow ID throw `PersistenceException` ("Corrupted Workflow partition"). In those cases, ask the developer whether to point the persistence at a new, empty directory (on storage that survives deploys) or to delete the 3.x files once Case 7 is done. Report the files; do not delete them yourself.

Before (3.x):

```php
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Persistence\FilePersistence;

class HashedFilePersistence extends FilePersistence
{
    protected function getFilePath(string $workflowId): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'wf-'.md5($workflowId).'.store';
    }
}

try {
    $persistence = new FilePersistence($directory, 'neuron_workflow_', '.store');
} catch (WorkflowException $e) {
    // the directory does not exist
}
```

After (4.x):

```php
use NeuronAI\Workflow\Persistence\FilePersistence;

class HashedFilePersistence extends FilePersistence
{
    protected function filePath(string $partition): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'wf-'.md5($partition).'.store';
    }
}

if (!is_dir($directory)) {
    // only where the app must fail fast; otherwise the first write creates the directory
}
$persistence = new FilePersistence($directory);
```

### Case 4: A backend subclass that changed the stored format

1. Delete `serialize()` and `unserialize()` from persistence classes, and remove `SerializablePersistenceInterface` from `implements` lists. 4.x never calls them.
2. Move a custom format (compression, encryption, igbinary) into a class implementing `NeuronAI\Workflow\Persistence\Serializer`.
   - It must round-trip any PHP value, not only interrupts.
   - It must throw `PersistenceException` on data it cannot decode.
   - For igbinary, use the built-in `NeuronAI\Workflow\Persistence\IgbinarySerializer`, which needs ext-igbinary.
3. Register the serializer on every workflow and agent that uses the store. Call `setSerializer()` where the persistence is configured, or override the `serializer()` hook next to the `persistence()` hook. Every process that continues a workflow ID must use the same serializer, including a standalone `WorkflowEngine` (Case 6).

Before (3.x):

```php
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\DatabasePersistence;

class CompressedPersistence extends DatabasePersistence
{
    public function serialize(WorkflowInterrupt $interrupt): string
    {
        return gzcompress(serialize($interrupt));
    }

    public function unserialize(string $data): WorkflowInterrupt
    {
        return unserialize(gzuncompress($data));
    }
}

$workflow->setPersistence(new CompressedPersistence($pdo));
```

After (4.x):

```php
use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\Serializer;

class CompressedSerializer implements Serializer
{
    public function serialize(mixed $value): string
    {
        return gzcompress(serialize($value));
    }

    public function unserialize(string $data): mixed
    {
        $inflated = @gzuncompress($data);
        if ($inflated === false) {
            throw new PersistenceException('Unable to decode a persisted workflow record.');
        }

        return unserialize($inflated);
    }
}

$workflow->setPersistence(new DatabasePersistence($pdo))
    ->setSerializer(new CompressedSerializer());
```

Or, in the workflow or agent class:

```php
use NeuronAI\Workflow\Persistence\Serializer;

protected function serializer(): Serializer
{
    return new CompressedSerializer();
}
```

### Case 5: A custom `PersistenceInterface` implementation

First choice: delete the class and use a built-in backend.
- `DatabasePersistence` for PDO on MySQL/MariaDB, PostgreSQL or SQLite (on another database use `EloquentPersistence` in a Laravel app, or keep a custom backend).
- `EloquentPersistence` for a Laravel model.
- `RedisPersistence` for a phpredis client. It needs ext-redis; if the servers lack it, report that to the developer.
- `FilePersistence` for a single process.
- `InMemoryPersistence` for tests.

A subclass of a built-in backend that overrode `save()`, `load()` or `delete()`: delete the overrides now, even while you ask the developer about added behaviour. 4.x never calls them, and on an `EloquentPersistence` subclass a 3.x `save()` is a fatal error when the class loads, because `EloquentPersistence` now declares its own protected `save(string $partition, array $records): bool`. If they added behaviour (logging, metrics, tenancy), ask the developer how to keep it, since the new methods receive opaque records.

Before (3.x):

```php
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\SerializablePersistenceInterface;

class RedisWorkflowPersistence implements PersistenceInterface, SerializablePersistenceInterface
{
    public function __construct(protected \Redis $redis)
    {
    }

    public function save(string $workflowId, WorkflowInterrupt $interrupt): void
    {
        $this->redis->set('workflow:'.$workflowId, $this->serialize($interrupt));
    }

    public function load(string $workflowId): WorkflowInterrupt
    {
        $data = $this->redis->get('workflow:'.$workflowId);
        if (!is_string($data)) {
            throw new WorkflowException("No saved workflow found for ID: {$workflowId}.");
        }

        return $this->unserialize($data);
    }

    public function delete(string $workflowId): void
    {
        $this->redis->del('workflow:'.$workflowId);
    }

    public function serialize(WorkflowInterrupt $interrupt): string
    {
        return serialize($interrupt);
    }

    public function unserialize(string $data): WorkflowInterrupt
    {
        return unserialize($data);
    }
}

$workflow->setPersistence(new RedisWorkflowPersistence($redis));
```

After (4.x), with a built-in backend:

```php
use NeuronAI\Workflow\Persistence\RedisPersistence;

$workflow->setPersistence(new RedisPersistence($redis)); // one hash per workflow ID at 'neuron:workflow:<workflow ID>'
```

The optional second argument is the key prefix. Pick one that no 3.x key uses, or report the 3.x keys for deletion (Case 7).

When no built-in backend fits, implement the four methods of `NeuronAI\Workflow\Persistence\PersistenceInterface`. The method bodies are specific to your storage:

```php
use NeuronAI\Workflow\Persistence\PersistenceInterface;

class DynamoWorkflowPersistence implements PersistenceInterface
{
    /**
     * The value stored under ($partition, $key), or null when absent.
     */
    public function get(string $partition, string $key): ?string
    {
        // ...
    }

    /**
     * Atomically: if $conditionKey exists in $partition, write nothing and return false.
     * Otherwise write $conditionKey => $initialValue plus every $records entry, and return true.
     *
     * @param array<array-key, string> $records
     */
    public function initializeIfAbsent(
        string $partition,
        string $conditionKey,
        string $initialValue,
        array $records = [],
    ): bool {
        // ...
    }

    /**
     * Atomically: if $conditionKey is absent or not byte-identical to $expectedValue, write nothing
     * and return false. Otherwise upsert every $records entry, and return true.
     *
     * @param array<array-key, string> $records
     */
    public function writeIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
        array $records,
    ): bool {
        // ...
    }

    /**
     * Atomically: the same comparison; on a match delete the whole partition and return true.
     */
    public function deleteIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
    ): bool {
        // ...
    }
}
```

Rules for the implementation:
- The partition is the workflow ID: any non-empty string, which may contain `:` or `/`. Encode it where the storage needs that.
- Keys and values are opaque strings. Store them byte for byte, and never parse or unserialize them.
- Each mutation is one atomic operation: a transaction with a unique insert or a locked row, one Lua script, or a conditional transaction API. It is never a `get()` followed by separate writes.
- A condition mismatch returns `false` and writes nothing.
- A storage failure throws (for example `PersistenceException`). It never truncates or drops a write silently.
- `vendor/neuron-core/neuron-ai/src/Workflow/Persistence/InMemoryPersistence.php` shows the exact semantics. `RedisPersistence.php` and `DatabasePersistence.php` in the same directory are atomic implementations.

### Case 6: Code that calls a backend directly

4.x backends have no `load()`, `save()` or `delete()`. Manage runs through the workflow or agent bound to the ID.

1. `$persistence->load($id)->getRequest()` becomes `inspect()`.
   - `null` means nothing is persisted for the ID.
   - While the snapshot's status is `WorkflowStatus::Suspended`, its `interrupt` is the pending request.
   - On an agent, guide 29 migrates how approval requests are read.
2. `$persistence->load($id)->getState()` becomes `run(ExecutionRequest::resume(expectedRunId: $run->runId, expectedExecutionAttempt: $run->executionAttempt))`, called only while `$run = inspect()` reports `WorkflowStatus::Suspended`. Then it delivers no answer, runs no node and returns the paused state. Unguarded, `resume()` recovers a failed run and runs its nodes again.
3. `$persistence->delete($id)` becomes `abandon()`, which returns `false` when nothing was in flight.
   - On an agent, `abandon()` throws `AgentException` while a tool call awaits its approval or result.
   - Ask the developer which they want: reject the pending approvals (guide 29), or also discard the conversation with `resetConversation()`.
4. Delete direct `save()` calls, because the engine writes its own records. A test that seeded a paused run with `save()` must run the workflow until it pauses instead.
5. Replace matches on `'No saved workflow found'` with an `inspect() === null` check before continuing. Guide 15 migrates the continuation call itself.
6. Some code queries the 3.x table with SQL or a model, for example a dashboard listing paused runs. 4.x rows are encoded and not meant for querying, and no API lists runs across IDs. Call `inspect()` for each ID, and keep the app's own list of IDs if it needs one.
7. Code without a workflow class at hand, such as a cleanup command, can use `new WorkflowEngine($persistence, $serializer)`, which has `inspect($id)` and `abandon($id)`. Pass the serializer the workflows use, or omit it for the default. Use it for plain workflows only. For an agent thread ID, build the agent with the same persistence and message store and call its own `abandon()` or `resetConversation()` (step 3): `WorkflowEngine::abandon()` skips the agent's guard, so a thread discarded while an approval is pending keeps the unanswered tool call, and its next message throws `ChatHistoryException`.

Before (3.x):

```php
use NeuronAI\Exceptions\WorkflowException;

try {
    $request = $persistence->load($orderId)->getRequest();
    $state = $persistence->load($orderId)->getState();
} catch (WorkflowException $e) {
    if (!str_contains($e->getMessage(), 'No saved workflow found')) {
        throw $e;
    }
    $request = null;
}

$persistence->delete($orderId);
```

After (4.x):

```php
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowStatus;

$workflow = OrderWorkflow::make($orderId)->setPersistence($persistence);

$run = $workflow->inspect(); // null: nothing persisted for this ID
$request = $run?->status === WorkflowStatus::Suspended ? $run->interrupt : null;
if ($request !== null) {
    $state = $workflow->run(ExecutionRequest::resume(expectedRunId: $run->runId, expectedExecutionAttempt: $run->executionAttempt)); // the paused state; nothing runs
}

$workflow->abandon(); // false when nothing was in flight

// Without a workflow class (plain workflows only):
$engine = new WorkflowEngine($persistence);
$engine->inspect($orderId);
$engine->abandon($orderId);
```

### Case 7: Runs paused under 3.x and the old storage

4.x has no reader for 3.x records. Continuing an ID paused under 3.x reports that nothing is in flight (except the `FilePersistence` name clash in Case 3, step 4).

1. Tell the developer to drain the paused runs before deploying 4.x: on 3.x, resume every paused workflow and every pending agent tool approval until its turn finishes (reject the actions that must not run). Deleting a 3.x agent pause does not drain it. An agent thread left paused keeps, in the history guide 30 carries over, the user message whose tool call awaited approval, so its next 4.x message throws `ChatHistoryException` ("expected role assistant, got user"), and `abandon()` returns `false`. For threads that could not be drained, ask the developer whether to reset them with `resetConversation()` after the deploy (it deletes their history), to delete that trailing user message from the stored history, or to move their users to new threads.
2. After the upgrade, the old storage is unused: the `workflow_interrupts` table (or the old Eloquent table), `neuron_workflow_*.store` files, and custom-backend records or keys.
   - Ask the developer whether to generate the migration that drops the old table now or after the deploy.
   - Report the old files and keys to delete. Do not delete them yourself.

## Checklist

- No class implements `SerializablePersistenceInterface`. No persistence class keeps 3.x `save()`, `load()`, `delete()`, `serialize(WorkflowInterrupt)`, `unserialize()` or `getFilePath()` methods.
- Every custom backend was replaced by a built-in one, or implements the four 4.x methods atomically.
- Any custom codec is a `Serializer`, registered on every workflow and agent that uses the store and on any `WorkflowEngine`.
- A migration that creates the 4.x table exists for `DatabasePersistence` or `EloquentPersistence`. No `'workflow_interrupts'` argument remains. The Eloquent model uses `partition`, `key` and `value`, lists them as fillable, and has no `SoftDeletes`.
- For `EloquentPersistence`, guide 1 Case 2 was applied (the Laravel version is 10.21 or later, or the developer was told).
- Every PDO passed to `DatabasePersistence` uses `PDO::ERRMODE_EXCEPTION`, or the dedicated-PDO recommendation was reported.
- On MySQL/MariaDB, the strict SQL mode requirement was reported to the developer.
- No `FilePersistence` call or container definition passes prefix or ext arguments. No code expects the constructor to throw for a missing directory.
- Direct `load()`, `save()` and `delete()` calls are gone (replaced by `inspect()`, `abandon()` or a `WorkflowEngine`). No code matches `'No saved workflow found'`.
- The drain instruction and the old table, file and key cleanup were reported to the developer.
- Re-running searches 1 to 4 shows only 4.x usages and the app's existing 3.x migrations.
