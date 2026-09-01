<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use LogicException;
use NeuronAI\Workflow\Executor\ActiveSuspension;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Resume\ResumeInput;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowState;
use function array_values;

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
     * Continue a run through the typed public API. This compatibility helper
     * keeps existing test fixtures concise by addressing the run's first
     * active suspension; tests of batching construct ResumeInput explicitly.
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
            return $workflow->resume(expectedRunId: $expectedRunId);
        }

        $raw = $workflow->getPersistence()->get(
            (string) ($workflow->getWorkflowId() ?? $workflow->workflowId()),
            '__control',
        );
        $control = $raw === null ? null : $workflow->getSerializer()->unserialize($raw);
        if (!$control instanceof WorkflowControl || $control->suspensions === []) {
            return $workflow->resume([ResumeInput::event(1, $payload)], $expectedRunId);
        }

        $active = array_values($control->suspensions)[0];
        if (!$active instanceof ActiveSuspension) {
            throw new LogicException('The test helper found an invalid active suspension.');
        }

        $input = match (true) {
            $timedOut => ResumeInput::expired($active->suspension->id),
            $active->suspension->type === InterruptType::SleepUntil => ResumeInput::timer($active->suspension->id),
            default => ResumeInput::event($active->suspension->id, $payload),
        };

        return $workflow->resume([$input], $expectedRunId);
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
