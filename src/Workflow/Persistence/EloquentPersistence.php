<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use Illuminate\Database\Eloquent\Model;
use NeuronAI\Workflow\Executor\StepResult;

class EloquentPersistence implements PersistenceInterface
{
    public function __construct(
        protected string $modelClass,
        protected Serializer $serializer = new PhpSerializer(),
    ) {
    }

    public function save(string $runId, string $stepId, StepResult $result): void
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        $model->newQuery()->updateOrCreate([
            'run_id' => $runId,
            'step_id' => $stepId,
        ], [
            'result' => $this->serializer->serialize($result),
        ]);
    }

    public function load(string $runId, string $stepId): ?StepResult
    {
        /** @var Model&object{result: string} $model */
        $model = new $this->modelClass();

        $record = $model->newQuery()
            ->where('run_id', $runId)
            ->where('step_id', $stepId)
            ->first();

        if ($record === null) {
            return null;
        }

        return $this->serializer->unserialize($record->result);
    }

    public function delete(string $runId): void
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        $model->newQuery()
            ->where('run_id', $runId)
            ->delete();
    }
}
