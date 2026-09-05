<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\StepMemoizer;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Executor\WorkflowRunStore;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\WorkflowStatus;

class WorkflowTestStore
{
    public static function memoizer(PersistenceInterface $persistence, string $workflowId, string $stepId): StepMemoizer
    {
        $store = new WorkflowRunStore($persistence, new PhpSerializer(), $workflowId);
        if ($store->loadControl() === null) {
            $store->initialize(
                new WorkflowControl('test-run', WorkflowStatus::Running),
                new Ignition('test-run', new StartEvent()),
            );
        }

        return $store->memoizer($stepId);
    }
}
