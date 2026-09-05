<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * Uses a model's table and connection with the same SQL protocol as
 * DatabasePersistence. Records are opaque; model casts, scopes, and events
 * do not participate. Transactions remain managed by Laravel.
 */
class EloquentPersistence extends DatabasePersistence
{
    protected Connection $connection;

    /** @param class-string<Model> $modelClass */
    public function __construct(string $modelClass)
    {
        $model = new $modelClass();
        $this->connection = $model->getConnection();

        parent::__construct(
            $this->connection->getPdo(),
            $this->connection->getTablePrefix() . $model->getTable(),
        );
    }

    protected function transaction(Closure $operation): bool
    {
        return $this->connection->transaction($operation);
    }
}
