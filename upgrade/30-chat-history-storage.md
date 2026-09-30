# Upgrade: Chat history storage: one row per message, with message_id and archived_at

This guide changes only databases, stored files, the Eloquent model and code that reads stored rows or files directly. Leave every `new SQLChatHistory(...)`, `new EloquentChatHistory(...)`, `new FileChatHistory(...)`, `setChatHistory(...)` call and `chatHistory()` hook as it is: guide 31 replaces them.

## Summary

In 4.x, chat history is stored by the message stores that guide 31 puts in place of the 3.x backends. The SQL and Eloquent stores keep one row per message. Each row has a `message_id`, unique within its thread, and a nullable `archived_at`. Trimming no longer deletes messages. It sets `archived_at` on the row, or an `archived_at` key on the entry in a file. Every load filters on `archived_at IS NULL`, so a table without that column fails on first use.

| 3.x backend | 3.x storage | 4.x storage | Data stored by 3.x |
|---|---|---|---|
| `SQLChatHistory` | table `chat_history` by default: one row per thread, all messages as JSON in `messages` | a new per-message table, `chat_messages` by default | not read by 4.x: copy it once (Case 1) |
| `EloquentChatHistory` | the model's table: one row per message (`thread_id`, `role`, `content`, `meta`) | the same table plus `message_id` and `archived_at` | read as-is after the migration in Case 2 |
| `FileChatHistory` | one JSON file per key, named `{prefix}{key}{ext}` | the same content, named `{prefix}` . `rawurlencode($key)` . `{ext}` | read as-is; some keys need a file rename (Case 4) |
| `InMemoryChatHistory` | nothing | nothing | nothing to do |

4.x reads the `meta` stored by 3.x as-is. Never rewrite stored `meta` values.

Deployment order, which you report to the developer: stop every 3.x process that writes chat history (web, queue workers), run this guide's migrations and scripts, then start 4.x. The SQL copy skips any thread that 4.x has already written to. 3.x cannot insert rows once Case 2 has made `message_id` NOT NULL.

## What to Search For

```bash
grep -rnE '(SQL|Eloquent|File)ChatHistory\b' --include='*.php' --exclude-dir=vendor .
grep -rnE 'chat_history\b' --include='*.php' --include='*.sql' --include='*.yaml' --include='*.yml' --include='*.env*' --exclude-dir=vendor .
```

Find the storage behind each backend from its constructor arguments, positional or named:

- `new SQLChatHistory($thread_id, $pdo, $table = 'chat_history', $contextWindow)`: the legacy table is the third argument or `table:`. If it is not `chat_history`, grep for that name as well.
- `new EloquentChatHistory($threadId, $modelClass, $contextWindow)`: the model is the second argument or `modelClass:`. Its table is the model's `$table`, or Laravel's default name for the class (`ChatMessage` becomes `chat_messages`).
- `new FileChatHistory($directory, $key, $contextWindow, $prefix = 'neuron_', $ext = '.chat')`: note the directory, how the key is built, the prefix and the extension.

Then find code that reads stored chat data directly:

```bash
grep -rnE "chat_messages|['\"]thread_id['\"]|thread_id\s*=" --include='*.php' --include='*.sql' --exclude-dir=vendor .
grep -rnE "['\"]archived_at['\"]" --include='*.php' --exclude-dir=vendor .
```

Also grep for the table names found above, for the Eloquent model class followed by `::` (for example `ChatMessage::`), and for the file directory or the `.chat` extension. 3.x never used `archived_at`, so any match of the second grep is the application's own. Case 2 and Case 4 say what to do with it.

If none of these searches finds anything, this guide does not apply.

## How to Refactor

### Case 1: `SQLChatHistory`: create the per-message table and copy the threads

1. Create the per-message table in the application's migration system.
   - If the 3.x code used the default `chat_history`, name the new table `chat_messages`. That is the 4.x default, so guide 31 needs no table argument.
   - If it used a custom table, ask the developer what to name the new table. The name must differ from the legacy table, because the copy reads one table and writes the other.
   - Record the name in your report: guide 31 passes it to the store.

   MySQL and MariaDB:

   ```sql
   CREATE TABLE chat_messages (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       thread_id VARBINARY(255) NOT NULL,
       message_id VARBINARY(64) NOT NULL,
       role VARCHAR(32) NOT NULL,
       content LONGTEXT NULL,
       meta LONGTEXT NULL,
       archived_at DATETIME NULL,
       created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
       updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       UNIQUE INDEX idx_thread_message (thread_id, message_id)
   );
   ```

   PostgreSQL (on SQLite, write `id INTEGER PRIMARY KEY AUTOINCREMENT`):

   ```sql
   CREATE TABLE chat_messages (
       id BIGSERIAL PRIMARY KEY,
       thread_id VARCHAR(255) NOT NULL,
       message_id VARCHAR(64) NOT NULL,
       role VARCHAR(32) NOT NULL,
       content TEXT NULL,
       meta TEXT NULL,
       archived_at TIMESTAMP NULL,
       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       UNIQUE (thread_id, message_id)
   );
   ```

   Laravel (11 or later):

   ```php
   use Illuminate\Database\Migrations\Migration;
   use Illuminate\Database\Schema\Blueprint;
   use Illuminate\Support\Facades\Schema;

   return new class extends Migration
   {
       public function up(): void
       {
           // MySQL and MariaDB collations ignore case and accents: the IDs must compare byte by byte there.
           $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

           Schema::create('chat_messages', function (Blueprint $table) use ($mysql) {
               $table->id();
               if ($mysql) {
                   $table->binary('thread_id', 255);
                   $table->binary('message_id', 64);
               } else {
                   $table->string('thread_id', 255);
                   $table->string('message_id', 64);
               }
               $table->string('role', 32);
               $table->longText('content')->nullable();
               $table->longText('meta')->nullable();
               $table->timestamp('archived_at')->nullable();
               $table->timestamps();
               $table->unique(['thread_id', 'message_id']);
           });
       }
   };
   ```

   On MySQL and MariaDB the ID columns stay `VARBINARY`, because the default collations ignore case and accents, so `user-Alice` and `user-alice` would share a thread. Never change them to `VARCHAR`. On Laravel 10, `binary()` takes no length: on MySQL and MariaDB, run the statement above through `DB::statement()` instead.

2. Copy the legacy threads once. The migration class ships as `vendor/neuron-core/neuron-ai/upgrade/SQLChatHistoryMigration.stub`.
   - Copy it into the application as a class file, for example `app/Support/SQLChatHistoryMigration.php`.
   - Add the application namespace after `declare(strict_types=1);`.
   - Add `use PDO;` and `use Throwable;` next to its `use NeuronAI\Exceptions\ChatHistoryException;`. Without them a namespaced copy rejects a real PDO and never rolls back.
   - Call it from a one-off console command or deploy script. `$pdo` is the connection the application passed to `SQLChatHistory`:

   ```php
   use App\Support\SQLChatHistoryMigration; // wherever the copy was placed

   $migrated = (new SQLChatHistoryMigration(pdo: $pdo, from: 'chat_history', to: 'chat_messages'))->run();
   ```

   - Or call it from a Laravel migration, which must not run inside a transaction. Use `DB::connection('<name>')` if the chat tables are not on the default connection:

   ```php
   use App\Support\SQLChatHistoryMigration;
   use Illuminate\Database\Migrations\Migration;
   use Illuminate\Support\Facades\DB;

   return new class extends Migration
   {
       public $withinTransaction = false;

       public function up(): void
       {
           (new SQLChatHistoryMigration(DB::connection()->getPdo(), from: 'chat_history', to: 'chat_messages'))->run();
       }
   };
   ```

   How it behaves:
   - Both tables must exist. It supports MySQL, MariaDB, PostgreSQL and SQLite.
   - It opens its own transaction and rolls back on failure. Calling it inside an open transaction fails with `There is already an active transaction`.
   - It skips threads that already have rows in the target table, so running it twice is safe. A thread that 4.x has already written to is never copied (see the deployment order in the Summary).
   - Each row's `message_id` is the message's stored `__id`. A message without one gets a generated ID, and an ID repeated within a thread is written once.
   - It returns the number of copied messages. Empty 3.x threads produce no rows.
   - It leaves the legacy table untouched. Tell the developer that it can be dropped (`DROP TABLE chat_history;`) in a later migration once the data is verified. Do not add the drop.

3. Rewrite code that reads the legacy table as described in Case 3.

### Case 2: `EloquentChatHistory`: add `message_id` and `archived_at` to the model's table

1. Write a migration for each model's table. Replace `chat_messages` with the real table name everywhere.

   ```php
   use Illuminate\Database\Migrations\Migration;
   use Illuminate\Database\Schema\Blueprint;
   use Illuminate\Support\Facades\DB;
   use Illuminate\Support\Facades\Schema;

   return new class extends Migration
   {
       public function up(): void
       {
           Schema::table('chat_messages', function (Blueprint $table) {
               $table->string('message_id', 64)->nullable()->after('thread_id');
               $table->timestamp('archived_at')->nullable();
           });

           // Each row takes the message identity 3.x stored in meta.
           DB::table('chat_messages')->chunkById(500, function ($rows) {
               foreach ($rows as $row) {
                   $messageId = json_decode($row->meta ?? '{}', true)['__id'] ?? "legacy_{$row->id}";
                   DB::table('chat_messages')->where('id', $row->id)->update(['message_id' => $messageId]);
               }
           });

           // A message stored twice in a thread keeps its identity on the first row; later rows get a suffix.
           $duplicates = DB::table('chat_messages')
               ->select('thread_id', 'message_id')
               ->selectRaw('MIN(id) AS first_id')
               ->groupBy('thread_id', 'message_id')
               ->havingRaw('COUNT(*) > 1')
               ->get();

           foreach ($duplicates as $duplicate) {
               DB::table('chat_messages')
                   ->where('thread_id', $duplicate->thread_id)
                   ->where('message_id', $duplicate->message_id)
                   ->where('id', '>', $duplicate->first_id)
                   ->pluck('id')
                   ->each(fn ($id) => DB::table('chat_messages')
                       ->where('id', $id)
                       ->update(['message_id' => "{$duplicate->message_id}_{$id}"]));
           }

           if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
               // Thread and message IDs must compare byte by byte: the default collations ignore case and accents.
               DB::statement('ALTER TABLE chat_messages MODIFY thread_id VARBINARY(255) NOT NULL, MODIFY message_id VARBINARY(64) NOT NULL');
           } else {
               Schema::table('chat_messages', function (Blueprint $table) {
                   $table->string('message_id', 64)->nullable(false)->change();
               });
           }

           Schema::table('chat_messages', function (Blueprint $table) {
               $table->unique(['thread_id', 'message_id']);
           });
       }
   };
   ```

   - `legacy_{id}` covers rows written by early 3.x releases, which did not store `__id`.
   - Existing rows keep `archived_at` NULL, which means active. 3.x deleted trimmed rows, so there is nothing to backfill.
   - `->change()` needs `doctrine/dbal` on Laravel 10.
   - If the table already has its own `message_id` or `archived_at` column, stop and ask the developer: 4.x would read that column as the message identity or the archive mark.

2. Make `message_id` fillable on the model, unless the model uses `$guarded = []`. The 4.x store creates rows with `firstOrCreate()`, which silently drops attributes that are not fillable, and the insert then fails on the NOT NULL `message_id`. Keep the `content` and `meta` array casts.

   Before (3.x):

   ```php
   use Illuminate\Database\Eloquent\Model;

   class ChatMessage extends Model
   {
       protected $fillable = ['thread_id', 'role', 'content', 'meta'];

       protected $casts = ['content' => 'array', 'meta' => 'array'];
   }
   ```

   After (4.x):

   ```php
   use Illuminate\Database\Eloquent\Model;

   class ChatMessage extends Model
   {
       protected $fillable = ['thread_id', 'message_id', 'role', 'content', 'meta'];

       protected $casts = ['content' => 'array', 'meta' => 'array'];
   }
   ```

3. 4.x orders a thread by the model's key, where 3.x ordered by the `id` column. If the model sets another `$primaryKey`, or its key is a UUIDv4, tell the developer: the key must follow insertion order (auto-increment, ULID or UUIDv7).

### Case 3: Code that reads stored rows directly

This covers code that reads the legacy `chat_history` table (Case 1) and code that queries the Eloquent model or its table (Case 2).

In 3.x the stored rows of a thread were exactly the model context, because trimming deleted the rest. In 4.x a thread keeps its archived rows too.

- Each row holds one message. Order a thread by `id`. The columns are `role`, `content` (a JSON array of content blocks) and `meta` (a JSON object with `__id`, `usage`, the tool `type`/`tools` and the message metadata). Guide 34 covers where the metadata sits inside `meta`.
- To get the rows 3.x returned, add `archived_at IS NULL` or `->whereNull('archived_at')`.
- If the code shows a conversation to people (transcript, export, admin view), ask the developer whether it should now include the archived messages. If it should, leave the filter out.
- To list threads, use `SELECT DISTINCT thread_id`. A thread has rows only after its first stored message, while 3.x inserted an empty `chat_history` row when a thread was first loaded.
- After guide 31, the store's `loadActive()` and `loadAll()` can replace these queries.

Before (3.x, the legacy table):

```php
$stmt = $pdo->prepare('SELECT messages FROM chat_history WHERE thread_id = :thread_id');
$stmt->execute(['thread_id' => $threadId]);
$messages = json_decode((string) $stmt->fetchColumn(), true) ?? [];

foreach ($messages as $message) {
    echo $message['role'] . ': ' . ($message['content'][0]['content'] ?? '') . PHP_EOL;
}
```

After (4.x):

```php
$stmt = $pdo->prepare('SELECT role, content FROM chat_messages WHERE thread_id = :thread_id AND archived_at IS NULL ORDER BY id');
$stmt->execute(['thread_id' => $threadId]);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $content = json_decode((string) $row['content'], true) ?? [];
    echo $row['role'] . ': ' . ($content[0]['content'] ?? '') . PHP_EOL;
}
```

Before (3.x, the Eloquent model):

```php
$messages = ChatMessage::query()->where('thread_id', $threadId)->orderBy('id')->get();
```

After (4.x):

```php
$messages = ChatMessage::query()->where('thread_id', $threadId)->whereNull('archived_at')->orderBy('id')->get();
```

Trimming no longer limits storage: rows are deleted only when a whole thread is cleared. If the application relied on trimming to limit what it keeps (privacy, table size), tell the developer that a retention job on `archived_at` is needed. Do not add one.

### Case 4: `FileChatHistory`: file names, permissions and archived entries

- **Content.** 3.x files load as-is. Entries without `__id` receive one on the thread's next write. Trimmed entries stay in the file with an `archived_at` key (an ISO 8601 string). Code that parses `.chat` files directly must skip those entries when it means the model context, and the transcript question of Case 3 applies to it too.
- **File name.** 3.x named the file after the raw key. 4.x uses `rawurlencode($key)`, and a name longer than 255 bytes becomes `{prefix}+{sha256 of the key}{ext}`. Keys made only of `A-Z a-z 0-9 - _ . ~` keep their file name. Check how the application builds the key: `'user-' . $user->id` is safe, but an e-mail address is not. If a key can contain any other character, the existing files must be renamed, or 4.x starts those threads empty. Add this script as a one-off command or deploy step, once per directory. Tell the developer to run it exactly once, after 3.x stops and before 4.x serves traffic: a second run renames keys containing `%` again. Do not run it yourself.

  ```php
  $directory = '/path/to/chats'; // the $directory given to FileChatHistory
  $prefix = 'neuron_';           // its $prefix
  $ext = '.chat';                // its $ext

  foreach (glob($directory . DIRECTORY_SEPARATOR . $prefix . '*' . $ext) as $path) {
      $key = substr(basename($path), strlen($prefix), -strlen($ext));
      $name = $prefix . rawurlencode($key) . $ext;

      if (strlen($name) > 255) {
          $name = $prefix . '+' . hash('sha256', $key) . $ext;
      }

      $target = $directory . DIRECTORY_SEPARATOR . $name;

      if ($target !== $path) {
          rename($path, $target);
      }
  }
  ```

  If the 3.x `$prefix` contains a folder, append that folder to `$directory` and keep the rest as `$prefix` (guide 31 moves it the same way). A 3.x key containing `/` wrote into a subdirectory: tell the developer that those files must be moved by hand.
- **Permissions.** 4.x rewrites a thread file readable by its owner only (0600). If another OS user reads these files (web server and queue worker running as different users, backup jobs), tell the developer.
- **An `archived_at` metadata key.** If the application sets a message metadata key named `archived_at`, tell the developer. 4.x reads that key on entries written by 3.x as the archive mark, so those messages drop out of the model context. Ask whether to rename the key in the stored files before 4.x serves traffic.

## Checklist

- The per-message SQL table exists with `id`, `thread_id`, `message_id`, `role`, `content`, `meta`, `archived_at`, `created_at`, `updated_at` and a unique `(thread_id, message_id)`. On MySQL and MariaDB both ID columns are `VARBINARY`.
- The copy of `SQLChatHistoryMigration` has the application namespace plus `use PDO;` and `use Throwable;`. It is called once from a one-off command, a deploy script or a migration with `$withinTransaction = false`.
- Your report records the per-message table name for guide 31.
- Every Eloquent chat table has `message_id` NOT NULL, a nullable `archived_at` and a unique `(thread_id, message_id)`. On MySQL and MariaDB `thread_id` and `message_id` are `VARBINARY`. The model lists `message_id` in `$fillable`, or uses `$guarded = []`.
- No code reads `chat_history`.`messages` any more. Direct queries that mean the 3.x rows filter on `archived_at IS NULL`, and transcript views were raised with the developer.
- The deployment order, file renames, file permissions and any retention need are reported to the developer.
- The `SQLChatHistory`, `EloquentChatHistory` and `FileChatHistory` constructor calls are unchanged (guide 31).
