<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use NeuronAI\Exceptions\PersistenceException;

use function base64_decode;
use function base64_encode;
use function bin2hex;
use function preg_match;
use function strlen;

/**
 * The model needs its own primary key and a unique (partition, key) constraint.
 * Partition/key columns hold up to 510 hexadecimal characters; value holds base64
 * bytes. Configure mass assignment and timestamps on the model. Casts must
 * preserve these strings, and records must be physically deleted (no SoftDeletes).
 */
class EloquentPersistence implements PersistenceInterface
{
    /** @param class-string<Model> $modelClass */
    public function __construct(protected string $modelClass)
    {
    }

    public function get(string $partition, string $key): ?string
    {
        $record = $this->records()->where($this->address($partition, $key))->first();
        if ($record === null) {
            return null;
        }

        $decoded = base64_decode((string) $record->getAttribute('value'), true);

        return $decoded === false
            ? throw new PersistenceException("Invalid encoded Workflow record '{$key}' in partition '{$partition}'.")
            : $decoded;
    }

    public function initializeIfAbsent(
        string $partition,
        string $conditionKey,
        string $initialValue,
        array $records = [],
    ): bool {
        return $this->atomically(fn (): bool => $this->create($partition, $conditionKey, $initialValue)
            && $this->save($partition, $records));
    }

    public function writeIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
        array $records,
    ): bool {
        return $this->atomically(fn (): bool => $this->holds($partition, $conditionKey, $expectedValue)
            && $this->save($partition, $records));
    }

    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
        return $this->atomically(fn (): bool => $this->holds($partition, $conditionKey, $expectedValue)
            && $this->purge($partition));
    }

    /** @param Closure(): bool $operation */
    protected function atomically(Closure $operation): bool
    {
        $connection = (new $this->modelClass())->getConnection();

        return $connection->transaction(function () use ($connection, $operation): bool {
            if ($connection instanceof MySqlConnection
                && !preg_match('/STRICT_(TRANS|ALL)_TABLES/', $connection->selectOne('SELECT @@SESSION.sql_mode AS mode')->mode)
            ) {
                throw new PersistenceException('Workflow persistence requires MySQL strict SQL mode to prevent truncated records.');
            }

            return $operation();
        });
    }

    protected function create(string $partition, string $key, string $value): bool
    {
        // Eloquent uses a savepoint for a competing unique-key insert,
        // keeping PostgreSQL's enclosing transaction usable on conflict.
        $record = $this->records()->createOrFirst($this->address($partition, $key), ['value' => base64_encode($value)]);
        $record->exists || throw new PersistenceException('Workflow record creation was cancelled by the Eloquent model.');

        return $record->wasRecentlyCreated;
    }

    protected function holds(string $partition, string $key, string $expectedValue): bool
    {
        $condition = $this->records()->where($this->address($partition, $key));
        $base = $condition->toBase();
        if ($base->getConnection() instanceof SQLiteConnection) {
            // Acquire the writer lock before any reads: SQLite cannot lock a
            // selected row, and upgrading a read transaction races with writers.
            $base->update(['value' => $base->raw($base->getGrammar()->wrap('value'))]);
        }

        $condition->lockForUpdate();

        return $condition->first()?->getAttribute('value') === base64_encode($expectedValue);
    }

    /** @param array<string, string> $records */
    protected function save(string $partition, array $records): bool
    {
        foreach ($records as $key => $value) {
            $this->records()->firstOrNew($this->address($partition, (string) $key))
                ->fill(['value' => base64_encode($value)])
                ->save() || throw new PersistenceException('Workflow record save was cancelled by the Eloquent model.');
        }

        return true;
    }

    protected function purge(string $partition): bool
    {
        $this->records()->where('partition', $this->encodeKey($partition))->get()->each(
            fn (Model $record): bool => $record->delete()
                || throw new PersistenceException('Workflow record deletion was cancelled by the Eloquent model.'),
        );

        return true;
    }

    /** @return Builder<Model> */
    protected function records(): Builder
    {
        $query = $this->modelClass::query();
        $query->useWritePdo();

        return $query;
    }

    /** @return array{partition: string, key: string} */
    protected function address(string $partition, string $key): array
    {
        return ['partition' => $this->encodeKey($partition), 'key' => $this->encodeKey($key)];
    }

    protected function encodeKey(string $key): string
    {
        if (strlen($key) > 255) {
            throw new PersistenceException('Workflow SQL partition names and record keys must not exceed 255 bytes.');
        }

        return bin2hex($key);
    }
}
