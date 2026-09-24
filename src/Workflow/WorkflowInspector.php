<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\WorkflowControl;
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

    /**
     * @throws WorkflowException
     */
    public function inspect(string $workflowId): ?WorkflowRunSnapshot
    {
        $store = new WorkflowRunStore($this->persistence, $this->serializer, $workflowId);
        $control = $store->loadControl();

        while ($control instanceof WorkflowControl) {
            $ignition = $store->loadIgnition();
            if ($ignition instanceof Ignition && $ignition->runId === $control->runId) {
                return new WorkflowRunSnapshot(
                    $control->runId,
                    $control->status,
                    $control->executionAttempt,
                    $control->interrupt?->request,
                    $workflowId,
                    $ignition->startEvent,
                );
            }

            // Only a run that ended or was replaced between the two reads may miss its ignition.
            $current = $store->loadControl();
            if ($current?->runId === $control->runId) {
                throw new WorkflowException("Run '{$control->runId}' for workflow ID '{$workflowId}' has no ignition record.");
            }
            $control = $current;
        }

        return null;
    }
}
