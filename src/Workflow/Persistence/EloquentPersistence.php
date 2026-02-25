<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use Illuminate\Database\Eloquent\Model;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

use function serialize;
use function unserialize;

class EloquentPersistence implements PersistenceInterface
{
    public function __construct(protected string $modelClass)
    {
    }

    public function save(string $workflowId, WorkflowInterrupt $interrupt): void
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        $model->newQuery()->updateOrCreate([
            'workflow_id' => $workflowId,
        ], [
            'interrupt' => serialize($interrupt),
        ]);
    }

    public function load(string $workflowId): WorkflowInterrupt
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        $record = $model->newQuery()
            ->where('workflow_id', $workflowId)
            ->firstOr(['interrupt'], fn () => throw new WorkflowException("No saved workflow found for ID: {$workflowId}."));

        return unserialize($record->interrupt);
    }

    public function delete(string $workflowId): void
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        $model->newQuery()
            ->where('workflow_id', $workflowId)
            ->delete();
    }
}
