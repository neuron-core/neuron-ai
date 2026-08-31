<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent-backed store: one model over the same single table as
 * DatabasePersistence — `partition`, `key`, and `value` string columns (plus
 * whatever furniture the application likes; the contract never reads it).
 * A thin adapter over the same opaque atomic store contract.
 */
class EloquentPersistence implements PersistenceInterface
{
    public function __construct(
        protected string $modelClass,
    ) {
    }

    protected function upsert(string $partition, string $key, string $value): void
    {
        $this->model()->newQuery()->upsert(
            [['partition' => $partition, 'key' => $key, 'value' => $value]],
            ['partition', 'key'],
            ['value'],
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

    protected function deletePartition(string $partition): void
    {
        $this->model()->newQuery()
            ->where('partition', $partition)
            ->delete();
    }

    public function initializeIfAbsent(
        string $partition,
        string $conditionKey,
        string $initialValue,
        array $records = [],
    ): bool {
        $records[$conditionKey] = $initialValue;

        return $this->commitIf($partition, $conditionKey, null, $records);
    }

    public function writeIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
        array $records,
    ): bool {
        return $this->commitIf($partition, $conditionKey, $expectedValue, $records);
    }

    public function deleteIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
    ): bool {
        return $this->commitIf($partition, $conditionKey, $expectedValue, deletePartition: true);
    }

    /** @param array<string, string> $writes */
    protected function commitIf(
        string $partition,
        string $conditionKey,
        ?string $expectedValue,
        array $writes = [],
        bool $deletePartition = false,
    ): bool {
        $model = $this->model();

        return $model->getConnection()->transaction(function () use (
            $partition,
            $conditionKey,
            $expectedValue,
            $writes,
            $deletePartition,
        ): bool {
            if ($expectedValue === null) {
                if (!isset($writes[$conditionKey])) {
                    return false;
                }

                $inserted = $this->model()->newQuery()->insertOrIgnore([
                    'partition' => $partition,
                    'key' => $conditionKey,
                    'value' => $writes[$conditionKey],
                ]);

                if ($inserted !== 1) {
                    return false;
                }

                unset($writes[$conditionKey]);
            } else {
                $current = $this->model()->newQuery()
                    ->where('partition', $partition)
                    ->where('key', $conditionKey)
                    ->lockForUpdate()
                    ->value('value');

                if ($current === null || (string) $current !== $expectedValue) {
                    return false;
                }
            }

            if ($deletePartition) {
                $this->deletePartition($partition);
                return true;
            }

            foreach ($writes as $key => $value) {
                $this->upsert($partition, $key, $value);
            }

            return true;
        });
    }

    protected function model(): Model
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        return $model;
    }
}
