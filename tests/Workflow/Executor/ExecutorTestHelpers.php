<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Executor\Scheduler\NullScheduler;
use NeuronAI\Workflow\Executor\Scheduler\SchedulerInterface;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowState;

use function iterator_to_array;

trait ExecutorTestHelpers
{
    protected function createExecutor(
        ?PersistenceInterface $persistence = null,
        ?SchedulerInterface $scheduler = null,
    ): WorkflowExecutorInterface {
        return new WorkflowExecutor(
            new LocalStepEngine($persistence ?? new InMemoryPersistence()),
            $scheduler ?? new NullScheduler(),
        );
    }

    protected function execute(WorkflowInterface $workflow, ?WorkflowExecutorInterface $executor = null, ?InterruptRequest $interrupt = null): WorkflowState
    {
        $executor ??= $this->createExecutor();
        $gen = $executor->execute($workflow, $interrupt);
        iterator_to_array($gen);
        return $gen->getReturn();
    }

    /**
     * @return array{0: WorkflowState, 1: object[]}
     */
    protected function executeAndCollect(WorkflowInterface $workflow, ?WorkflowExecutorInterface $executor = null, ?InterruptRequest $interrupt = null): array
    {
        $executor ??= $this->createExecutor();
        $events = [];
        $gen = $executor->execute($workflow, $interrupt);
        foreach ($gen as $event) {
            $events[] = $event;
        }
        return [$gen->getReturn(), $events];
    }
}
