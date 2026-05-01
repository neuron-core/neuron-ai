<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

use Illuminate\Database\Eloquent\Model;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

use function base64_decode;
use function base64_encode;
use function serialize;
use function unserialize;

class EloquentPersistence implements PersistenceInterface, SerializablePersistenceInterface
{
    public function __construct(protected string $modelClass)
    {
    }

    public function serialize(WorkflowInterrupt $interrupt): string
    {
        return serialize($interrupt);
    }

    public function unserialize(string $data): WorkflowInterrupt
    {
        return unserialize($data);
    }

    public function save(string $workflowId, WorkflowInterrupt $interrupt): void
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        $model->newQuery()->updateOrCreate([
            'workflow_id' => $workflowId,
        ], [
            // Simple Base64 string is compatible with all databases
            'interrupt' => base64_encode($this->serialize($interrupt)),
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

        return $this->unserialize($interruptData);
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
