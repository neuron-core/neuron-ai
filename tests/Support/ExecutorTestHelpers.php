<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use NeuronAI\Workflow\Executor\BranchRunner;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowState;
use LogicException;

use function spl_object_id;

trait ExecutorTestHelpers
{
    /** @var array<int, ExecutionRecorder> */
    protected array $executionRecords = [];
    /**
     * The storage key of a node step — the engine's runId-prefixed record
     * layout, stated once for every test that reads the store directly.
     */
    protected function stepKey(WorkflowInterface $workflow, string $stepId): string
    {
        $runId = $this->executionRecords[spl_object_id($workflow)]->context->runId ?? $workflow->inspect()?->runId;
        if ($runId === null) {
            throw new LogicException('Record an execution before addressing its steps.');
        }
        return $runId . '/' . $stepId;
    }

    /**
     * Branch runner for runs driven through these helpers; null keeps the
     * workflow's default. Async test classes override this.
     */
    protected function branchRunner(): ?BranchRunner
    {
        return null;
    }

    protected function configure(
        Workflow $workflow,
        ?PersistenceInterface $persistence = null,
    ): Workflow {
        $this->executionRecords[spl_object_id($workflow)] ??= new ExecutionRecorder($workflow);
        $runner = $this->branchRunner();
        if ($runner instanceof BranchRunner) {
            $workflow->setBranchRunner($runner);
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
            return $workflow->run(ExecutionRequest::resume(null, expectedRunId: $expectedRunId));
        }

        $interrupt = $workflow->inspect()?->interrupt;
        $inputs = $interrupt instanceof InterruptRequest && ($timedOut || $interrupt->type() === InterruptType::SleepUntil)
            ? null
            : $payload;

        return $workflow->run(ExecutionRequest::resume($inputs, $expectedRunId));
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
