<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function max;
use function str_contains;

class CycleEvent implements Event
{
}

class CycleStartNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): CycleEvent
    {
        return new CycleEvent();
    }
}

class RunawayCycleNode extends Node
{
    public const ESCAPE_AFTER = 5000;

    public function __invoke(CycleEvent $event, WorkflowState $state): CycleEvent
    {
        $visits = $state->get('visits', 0) + 1;
        $state->set('visits', $visits);

        if ($visits >= self::ESCAPE_AFTER) {
            throw new RuntimeException('test escape hatch: the engine never stopped the cycle');
        }

        return new CycleEvent();
    }
}

class StepCountingPersistence extends InMemoryPersistence
{
    public int $peakStepRecords = 0;

    public function writeIfUnchanged(string $partition, string $conditionKey, string $expectedValue, array $records): bool
    {
        $written = parent::writeIfUnchanged($partition, $conditionKey, $expectedValue, $records);
        $steps = 0;
        foreach ($this->storage[$partition] ?? [] as $key => $value) {
            $steps += str_contains((string) $key, '__') ? 0 : 1;
        }
        $this->peakStepRecords = max($this->peakStepRecords, $steps);
        return $written;
    }
}

class WorkflowCycleBudgetTest extends TestCase
{
    public function test_a_never_stopping_cycle_fails_the_run_once_its_step_budget_is_spent(): void
    {
        $persistence = new StepCountingPersistence();
        $workflow = Workflow::make()
            ->setPersistence($persistence)
            ->setMaxSteps(100)
            ->addNodes([new CycleStartNode(), new RunawayCycleNode()]);

        try {
            $workflow->run();
            $this->fail('A non-terminating cycle must not complete.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('exceeded its budget of 100 steps', $e->getMessage());
        }

        $this->assertLessThanOrEqual(100, $persistence->peakStepRecords);
    }
}
