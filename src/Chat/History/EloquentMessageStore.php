<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;

use function array_merge;
use function max;

/**
 * Stores one row per message through the application's model, resolved with its
 * current connection on every call. The model key must follow insertion order
 * (auto-increment, ULID or UUIDv7, not UUIDv4) because it orders the thread;
 * message_id is the message identity.
 */
class EloquentMessageStore implements MessageStoreInterface
{
    protected const COLUMNS = ['message_id', 'role', 'content', 'meta'];

    /**
     * @param class-string<Model> $modelClass
     */
    public function __construct(protected string $modelClass)
    {
    }

    public function loadActive(string $threadId): array
    {
        $model = $this->model();

        $query = $this->writeQuery($model)->where('thread_id', $threadId);
        $query->whereNull('archived_at');

        return $this->loadInKeyOrder($model, $query);
    }

    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        $model = $this->model();
        $query = $model->newQuery()->where('thread_id', $threadId);

        if ($before !== null) {
            $cursor = $model->newQuery()
                ->where('thread_id', $threadId)
                ->where('message_id', $before)
                ->value($model->getKeyName());

            if ($cursor === null) {
                return [];
            }

            $query->where($model->getKeyName(), '<', $cursor);
        }

        if ($limit === null) {
            return $this->loadInKeyOrder($model, $query);
        }

        // A page is the newest rows before the cursor, returned in insertion order; a negative limit is an empty one.
        $ids = $query->latest($model->getKeyName())->limit(max(0, $limit))->pluck($model->getKeyName());

        if ($ids->isEmpty()) {
            return [];
        }

        $page = $model->newQuery();
        $page->whereIn($model->getKeyName(), $ids);

        return $this->loadInKeyOrder($model, $page);
    }

    public function append(string $threadId, Message $message): void
    {
        $this->writeQuery($this->model())->firstOrCreate(
            ['thread_id' => $threadId, 'message_id' => $message->getId()],
            [
                'role' => $message->getRole(),
                'content' => $message->getContentBlocks(),
                'meta' => $this->serializeMessageMeta($message),
            ]
        );
    }

    public function archive(string $threadId, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $model = $this->model();

        $ids = $this->writeQuery($model)
            ->where('thread_id', $threadId)
            ->whereNull('archived_at')
            ->orderBy($model->getKeyName())
            ->limit($count)
            ->pluck($model->getKeyName());

        $model->newQuery()
            ->whereIn($model->getKeyName(), $ids)
            ->update(['archived_at' => $model->freshTimestampString()]);
    }

    public function clear(string $threadId): void
    {
        $this->model()->newQuery()
            ->where('thread_id', $threadId)
            ->delete();
    }

    protected function model(): Model
    {
        return new $this->modelClass();
    }

    /**
     * Reads that precede a write use the write connection, so replica lag never
     * shows the working history a stale thread.
     */
    protected function writeQuery(Model $model): Builder
    {
        $query = $model->newQuery();
        $query->useWritePdo();

        return $query;
    }

    /**
     * Rows are sorted by key in PHP, never by the database: MySQL resolves the thread
     * through the unique index and then filesorts, copying the large content and meta
     * columns into its sort buffer, which one big tool output is enough to overflow.
     * A page sorts only the keys, then fetches its rows by key.
     *
     * @return Message[]
     */
    protected function loadInKeyOrder(Model $model, Builder $query): array
    {
        $key = $model->getKeyName();

        return $this->deserialize($query->get([$key, ...self::COLUMNS])->sortBy($key));
    }

    /**
     * @param iterable<Model> $records
     * @return Message[]
     */
    protected function deserialize(iterable $records): array
    {
        $deserializer = new MessageDeserializer();

        $messages = [];
        foreach ($records as $record) {
            $messages[] = $deserializer->deserialize($this->recordToArray($record));
        }

        return $messages;
    }

    /**
     * The message_id column is the identity of the record.
     *
     * @return array<string, mixed>
     */
    protected function recordToArray(Model $record): array
    {
        return array_merge((array) ($record->getAttribute('meta') ?? []), [
            'role' => $record->getAttribute('role'),
            'content' => $record->getAttribute('content'),
            '__id' => $record->getAttribute('message_id'),
        ]);
    }

    /**
     * Serialize message metadata for storage.
     *
     * @return array<string, mixed>
     */
    protected function serializeMessageMeta(Message $message): array
    {
        $serialized = $message->jsonSerialize();

        // Remove fields that are stored in separate columns
        unset($serialized['role'], $serialized['content']);

        return $serialized;
    }
}
