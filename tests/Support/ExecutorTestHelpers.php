<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Interrupt\InterruptType;
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
    ): Workflow {
        $executor = $this->executor();
        if ($executor instanceof WorkflowExecutorInterface) {
            $workflow->setExecutor($executor);
        }

        if ($persistence instanceof PersistenceInterface) {
            $workflow->setPersistence($persistence);
        }

        return $workflow;
    }

    protected function execute(
        Workflow $workflow,
        ?PersistenceInterface $persistence = null,
    ): WorkflowState {
        return $this->configure($workflow, $persistence)->run();
    }

    /**
     * Continue the current interruption through the public API.
     *
     * @param array<string, mixed>|null $payload
     */
    protected function resume(
        Workflow $workflow,
        ?PersistenceInterface $persistence = null,
        ?array $payload = [],
        bool $timedOut = false,
        ?string $expectedRunId = null,
    ): WorkflowState {
        $workflow = $this->configure($workflow, $persistence);
        if ($payload === null) {
            return $workflow->resume(null, expectedRunId: $expectedRunId)->run();
        }

        $raw = $workflow->getPersistence()->get(
            (string) ($workflow->getWorkflowId() ?? $workflow->workflowId()),
            '__control',
        );
        $control = $raw === null ? null : $workflow->getSerializer()->unserialize($raw);
        if (!$control instanceof WorkflowControl || $control->interrupt === null) {
            return $workflow->resume($payload, $expectedRunId)->run();
        }

        $active = $control->interrupt;

        $inputs = $timedOut || $active->request->type() === InterruptType::SleepUntil
            ? null
            : $payload;

        return $workflow->resume($inputs, $expectedRunId)->run();
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
