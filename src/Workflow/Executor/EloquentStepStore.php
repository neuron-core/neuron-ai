<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Illuminate\Database\Eloquent\Model;

use function base64_decode;
use function base64_encode;
use function serialize;
use function unserialize;

class EloquentStepStore implements StepStoreInterface
{
    public function __construct(protected string $modelClass)
    {
    }

    public function save(string $workflowId, string $stepId, StepResult $result): void
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        $model->newQuery()->updateOrCreate([
            'workflow_id' => $workflowId,
            'step_id' => $stepId,
        ], [
            'result' => base64_encode(serialize($result)),
        ]);
    }

    public function load(string $workflowId, string $stepId): ?StepResult
    {
        /** @var Model&object{result: string} $model */
        $model = new $this->modelClass();

        $record = $model->newQuery()
            ->where('workflow_id', $workflowId)
            ->where('step_id', $stepId)
            ->first();

        if ($record === null) {
            return null;
        }

        $data = base64_decode($record->result, true);

        if ($data === false) {
            $data = $record->result;
        }

        return unserialize($data);
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
