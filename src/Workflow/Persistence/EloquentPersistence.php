<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use Illuminate\Database\Eloquent\Model;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

use function serialize;
use function unserialize;
use function base64_decode;
use function base64_encode;

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
            // Simple Base64 string is compatible with all databases
            'interrupt' => base64_encode(serialize($interrupt)),
        ]);
    }

    /**
     * @throws WorkflowException
     */
    public function load(string $workflowId): WorkflowInterrupt
    {
        /** @var Model&object{interrupt: string} $model */
        $model = new $this->modelClass();

        $record = $model->newQuery()
            ->where('workflow_id', $workflowId)
            ->firstOr(['interrupt'], fn () => throw new WorkflowException("No saved workflow found for ID: {$workflowId}."));

        $interruptData = base64_decode($record->interrupt, true);

        if ($interruptData === false) {
            $interruptData = $record->interrupt; // This makes sure that previous records still work
        }

        return unserialize($interruptData);
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
