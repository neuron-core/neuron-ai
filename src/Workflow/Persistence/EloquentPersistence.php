<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use Illuminate\Database\Eloquent\Model;
use NeuronAI\Workflow\Executor\StepResult;

use function base64_decode;
use function base64_encode;
use function serialize;
use function unserialize;
use function max;

class EloquentPersistence implements PersistenceInterface
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

    public function getMaxGeneration(string $workflowId): int
    {
        /** @var Model $model */
        $model = new $this->modelClass();
        /** @var \Illuminate\Database\Eloquent\Collection<int, Model&object{result: string}> $records */
        $records = $model->newQuery()
            ->where('workflow_id', $workflowId)
            ->get();

        $max = 0;
        foreach ($records as $record) {
            $data = base64_decode((string) $record->result, true);
            if ($data === false) {
                $data = $record->result;
            }
            $result = unserialize($data);
            $max = max($max, $result->getGeneration());
        }
        return $max;
    }
}
