<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Workflow\Executor\DefaultNodeRunner;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowState;

trait ExecutorTestHelpers
{
    private function createExecutor(?InMemoryPersistence $persistence = null): WorkflowExecutorInterface
    {
        return new WorkflowExecutor(
            new DefaultNodeRunner(),
            $persistence ?? new InMemoryPersistence(),
        );
    }

    private function execute(WorkflowInterface $workflow, ?WorkflowExecutorInterface $executor = null, ?InterruptRequest $interrupt = null): WorkflowState
    {
        $executor ??= $this->createExecutor();
        $gen = $executor->execute($workflow, $interrupt);
        foreach ($gen as $event) {
        }
        return $gen->getReturn();
    }

    /**
     * @return array{0: WorkflowState, 1: object[]}
     */
    private function executeAndCollect(WorkflowInterface $workflow, ?WorkflowExecutorInterface $executor = null, ?InterruptRequest $interrupt = null): array
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
