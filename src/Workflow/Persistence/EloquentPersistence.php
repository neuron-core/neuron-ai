<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent-backed store: one model over the same single table as
 * DatabasePersistence — `partition`, `key`, and `value` string columns (plus
 * whatever furniture the application likes; the contract never reads it).
 * A thin adapter by design: the same three verbs, no special features.
 */
class EloquentPersistence implements PersistenceInterface
{
    public function __construct(
        protected string $modelClass,
    ) {
    }

    public function put(string $partition, string $key, string $value): void
    {
        $this->model()->newQuery()->updateOrCreate(
            ['partition' => $partition, 'key' => $key],
            ['value' => $value],
        );
    }

    public function get(string $partition, string $key): ?string
    {
        $value = $this->model()->newQuery()
            ->where('partition', $partition)
            ->where('key', $key)
            ->value('value');

        return $value === null ? null : (string) $value;
    }

    public function delete(string $partition): void
    {
        $this->model()->newQuery()
            ->where('partition', $partition)
            ->delete();
    }

    protected function model(): Model
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        return $model;
    }
}
