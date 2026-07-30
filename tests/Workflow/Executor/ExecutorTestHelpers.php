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
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

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

    protected function execute(Workflow $workflow, ?WorkflowExecutorInterface $executor = null): WorkflowState
    {
        return $workflow
            ->setExecutor($executor ?? $this->createExecutor())
            ->run();
    }

    /**
     * Resume a suspended workflow by delivering the payload through resume().
     *
     * @param array<string, mixed> $payload
     */
    protected function resume(Workflow $workflow, ?WorkflowExecutorInterface $executor = null, array $payload = [], bool $timedOut = false): WorkflowState
    {
        return $workflow
            ->setExecutor($executor ?? $this->createExecutor())
            ->resume($payload, $timedOut);
    }

    /**
     * @return array{0: WorkflowState, 1: object[]}
     */
    protected function executeAndCollect(Workflow $workflow, ?WorkflowExecutorInterface $executor = null): array
    {
        $workflow->setExecutor($executor ?? $this->createExecutor());
        $events = [];
        $gen = $workflow->events();
        foreach ($gen as $event) {
            $events[] = $event;
        }
        return [$gen->getReturn(), $events];
    }
}
