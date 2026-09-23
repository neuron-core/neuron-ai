<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Executor\WorkflowRunStore;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Persistence\Serializer;

class WorkflowInspector
{
    public function __construct(
        protected PersistenceInterface $persistence,
        protected Serializer $serializer = new PhpSerializer(),
    ) {
    }

    public function inspect(string $workflowId): ?WorkflowRunSnapshot
    {
        $store = new WorkflowRunStore($this->persistence, $this->serializer, $workflowId);
        $control = $store->loadControl();

        return $control === null ? null : new WorkflowRunSnapshot(
            $control->runId,
            $control->status,
            $control->executionAttempt,
            $control->interrupt?->request,
            $workflowId,
        );
    }
}
