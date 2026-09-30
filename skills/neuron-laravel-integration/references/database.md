# The Neuron tables in Laravel

One migration creates the two tables Neuron needs: `chat_messages` for `EloquentMessageStore` (the conversation) and `workflow_store` for `DatabasePersistence` (the durable runs). It ran unchanged on SQLite, MySQL 8.4, MariaDB 11.7 and PostgreSQL 17, each followed by a turn, an approval suspend and its continuation.

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The conversations (EloquentMessageStore) and the durable runs (DatabasePersistence) of the Neuron agents.
     */
    public function up(): void
    {
        // MySQL and MariaDB collations ignore case and accents: identifiers must compare byte by byte there.
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
        Schema::dropIfExists('chat_messages');
    }
};
```

What it produces:

| Driver | `thread_id` / `message_id` | `partition` / `key` | `value` |
|---|---|---|---|
| MySQL, MariaDB | `varbinary(255)` / `varbinary(64)` | `varchar(510) CHARACTER SET ascii COLLATE ascii_bin` | `longtext CHARACTER SET ascii` |
| PostgreSQL | `varchar(255)` / `varchar(64)` | `varchar(510)` | `text` |
| SQLite | `varchar` | `varchar` | `text` |

Why each choice:

- **`binary()` on MySQL and MariaDB.** Their default collations ignore case and accents: with `string()`, `user-1-CaseTest` and `user-1-casetest` read and clear each other's messages. PostgreSQL and SQLite compare `varchar` exactly, and `binary()` there would create `bytea`/`blob`.
- **`ascii_bin` only on MySQL and MariaDB.** PostgreSQL refuses it: `collation "ascii_bin" for encoding "UTF8" does not exist`.
- **`unique(thread_id, message_id)`.** `EloquentMessageStore` appends idempotently by message ID; the unique index also serves every thread lookup.
- **An auto-increment `id`.** The store orders a thread by the model key, so it must follow insertion order (auto-increment, ULID or UUIDv7, never UUIDv4).
- **`updated_at` with a default.** `DatabasePersistence` writes it on every record.
- **MySQL strict mode.** Keep the connection's `'strict' => true`: without it `DatabasePersistence` refuses to write ("Workflow persistence requires MySQL strict SQL mode to prevent truncated records.").

The model `EloquentMessageStore` writes through needs the five columns fillable and both JSON columns cast to arrays:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['thread_id', 'message_id', 'role', 'content', 'meta'])]
class ChatMessage extends Model
{
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'meta' => 'array',
        ];
    }
}
```

`EloquentPersistence(WorkflowRecord::class)` is the alternative to `DatabasePersistence`: it resolves the model's connection on every operation, so it can be a singleton. It needs a different table (a surrogate `id`, `unique(partition, key)`, no composite primary key, no soft deletes); the rules are in **neuron-workflow** ("Database Table Schema").
