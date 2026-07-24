<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Executor\NullScheduler;
use NeuronAI\Workflow\Executor\SchedulerInterface;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
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

    protected function execute(WorkflowInterface $workflow, ?WorkflowExecutorInterface $executor = null): WorkflowState
    {
        $executor ??= $this->createExecutor();
        $gen = $executor->execute($workflow);
        iterator_to_array($gen);
        return $gen->getReturn();
    }

    /**
     * Resume a suspended workflow by delivering the payload through execute().
     *
     * @param array<string, mixed> $payload
     */
    protected function resume(WorkflowInterface $workflow, ?WorkflowExecutorInterface $executor = null, array $payload = [], bool $timedOut = false): WorkflowState
    {
        $executor ??= $this->createExecutor();
        $gen = $executor->execute($workflow, $payload, $timedOut);
        iterator_to_array($gen);
        return $gen->getReturn();
    }

    /**
     * @return array{0: WorkflowState, 1: object[]}
     */
    protected function executeAndCollect(WorkflowInterface $workflow, ?WorkflowExecutorInterface $executor = null): array
    {
        $executor ??= $this->createExecutor();
        $events = [];
        $gen = $executor->execute($workflow);
        foreach ($gen as $event) {
            $events[] = $event;
        }
        return [$gen->getReturn(), $events];
    }
}
