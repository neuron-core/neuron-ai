<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Workflow\Executor\SchedulerInterface;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowState;

trait ExecutorTestHelpers
{
    /**
     * The storage key of a node step — the engine's runId-prefixed record
     * layout, stated once for every test that reads the store directly.
     */
    protected function stepKey(WorkflowInterface $workflow, string $stepId): string
    {
        return $workflow->getRunId() . '/' . $stepId;
    }

    /**
     * Executor for runs driven through these helpers; null keeps the
     * workflow's default. Async test classes override this.
     */
    protected function executor(): ?WorkflowExecutorInterface
    {
        return null;
    }

    protected function configure(
        Workflow $workflow,
        ?PersistenceInterface $persistence = null,
        ?SchedulerInterface $scheduler = null,
    ): Workflow {
        $executor = $this->executor();
        if ($executor instanceof WorkflowExecutorInterface) {
            $workflow->setExecutor($executor);
        }

        if ($persistence instanceof PersistenceInterface) {
            $workflow->setPersistence($persistence);
        }

        if ($scheduler instanceof SchedulerInterface) {
            $workflow->setScheduler($scheduler);
        }

        return $workflow;
    }

    protected function execute(
        Workflow $workflow,
        ?PersistenceInterface $persistence = null,
        ?SchedulerInterface $scheduler = null,
    ): WorkflowState {
        return $this->configure($workflow, $persistence, $scheduler)->run();
    }

    /**
     * Continue a run through resume(): [] delivers an empty answer (the
     * helper default), null revives without delivering anything.
     *
     * @param array<string, mixed>|null $payload
     */
    protected function resume(
        Workflow $workflow,
        ?PersistenceInterface $persistence = null,
        ?array $payload = [],
        bool $timedOut = false,
        ?SchedulerInterface $scheduler = null,
    ): WorkflowState {
        return $this->configure($workflow, $persistence, $scheduler)->resume($payload, $timedOut);
    }

    /**
     * @return array{0: WorkflowState, 1: object[]}
     */
    protected function executeAndCollect(Workflow $workflow, ?PersistenceInterface $persistence = null): array
    {
        $this->configure($workflow, $persistence);
        $events = [];
        $gen = $workflow->events();
        foreach ($gen as $event) {
            $events[] = $event;
        }
        return [$gen->getReturn(), $events];
    }
}
