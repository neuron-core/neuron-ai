<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use stdClass;

use function max;

/**
 * A step budget fails a run once one of its paths goes past it, so a routing
 * cycle that never stops cannot run and persist forever.
 */
class StepBudgetTest extends TestCase
{
    protected InMemoryPersistence $persistence;

    protected stdClass $trace;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
        $this->trace = (object) ['laps' => []];
    }

    protected function loop(?int $stopAt = null, ?int $waitAt = null): Workflow
    {
        return Workflow::make('budget')->setPersistence($this->persistence)->addNode($this->lapNode($stopAt, $waitAt));
    }

    /**
     * A node that routes back to itself, lap after lap, until $stopAt; at
     * $waitAt it waits for an answer first.
     */
    protected function lapNode(?int $stopAt = null, ?int $waitAt = null): Node
    {
        return new class ($this->trace, $stopAt, $waitAt) extends Node {
            public function __construct(protected stdClass $trace, protected ?int $stopAt, protected ?int $waitAt)
            {
            }

            public function __invoke(StartEvent $event, WorkflowState $state): StartEvent|StopEvent
            {
                $lap = $state->get('lap', 0) + 1;
                $state->set('lap', $lap);
                $this->trace->laps[] = $lap;
                if ($lap === $this->waitAt) {
                    $this->awaitEvent('go');
                }

                return $lap === $this->stopAt ? new StopEvent() : new StartEvent();
            }
        };
    }

    public function test_a_cycle_that_never_stops_fails_once_its_budget_is_spent(): void
    {
        try {
            $this->loop()->setMaxSteps(10)->run();
            $this->fail('A cycle that never stops must not run forever.');
        } catch (WorkflowException $e) {
            $this->assertSame("Workflow ID 'budget' exceeded its budget of 10 steps.", $e->getMessage());
        }

        $this->assertSame(10, max($this->trace->laps));
        $this->assertSame(WorkflowStatus::Failed, $this->loop()->inspect()?->status);
    }

    public function test_a_path_may_take_exactly_its_budget(): void
    {
        $state = $this->loop(stopAt: 10)->setMaxSteps(10)->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertSame(10, $state->get('lap'));
    }

    public function test_replayed_steps_count_toward_the_budget(): void
    {
        $this->assertTrue($this->loop(waitAt: 5)->setMaxSteps(10)->run()->isInterrupted());

        try {
            $this->loop(waitAt: 5)->setMaxSteps(10)->run(ExecutionRequest::resume(['go' => true]));
            $this->fail('Suspending must not reset the budget.');
        } catch (WorkflowException $e) {
            $this->assertSame("Workflow ID 'budget' exceeded its budget of 10 steps.", $e->getMessage());
        }

        $this->assertSame(10, max($this->trace->laps));
    }

    public function test_raising_the_budget_continues_the_failed_run_from_its_completed_steps(): void
    {
        try {
            $this->loop(stopAt: 8)->setMaxSteps(5)->run();
            $this->fail('Eight laps exceed a budget of five steps.');
        } catch (WorkflowException) {
        }

        $state = $this->loop(stopAt: 8)->setMaxSteps(10)->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $this->trace->laps);
    }

    public function test_each_branch_has_its_own_budget(): void
    {
        $text = new class () extends Node {
            public function __invoke(TextProcessEvent $event, WorkflowState $state): TextProcessEvent
            {
                return new TextProcessEvent();
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Workflow ID 'branches' exceeded its budget of 5 steps in branch 'text'.");

        Workflow::make('branches')
            ->setMaxSteps(5)
            ->addNodes([new DocumentParallelProcessing(), $text, new ImageProcessNode(), new MergeNode()])
            ->run();
    }

    public function test_the_hook_sets_a_default_that_the_setter_overrides(): void
    {
        $workflow = fn (): Workflow => (new class (workflowId: 'budget') extends Workflow {
            protected function maxSteps(): int
            {
                return 3;
            }
        })->setPersistence($this->persistence)->addNode($this->lapNode(stopAt: 8));

        try {
            $workflow()->run();
            $this->fail('The hook budget of three steps must apply.');
        } catch (WorkflowException $e) {
            $this->assertSame("Workflow ID 'budget' exceeded its budget of 3 steps.", $e->getMessage());
        }

        $state = $workflow()->setMaxSteps(null)->run();

        $this->assertSame(8, $state->get('lap'));
    }
}
