<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\InterruptableTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\RecordingObserver;
use NeuronAI\Tests\Workflow\Executor\Stub\ReplayBudgetSerializer;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Executor\BranchRunner;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Executor\SequentialBranchRunner;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_filter;
use function array_values;
use function in_array;

class BranchTraversalTest extends TestCase
{
    /** @return array<string, array{BranchRunner}> */
    public static function branchRunners(): array
    {
        return ['sequential' => [new SequentialBranchRunner()], 'async' => [new AsyncBranchRunner()]];
    }

    #[DataProvider('branchRunners')]
    public function test_branch_writes_stay_out_of_the_run_state(BranchRunner $runner): void
    {
        $make = fn (InMemoryPersistence $persistence): Workflow => Workflow::make('branch-state')
            ->setPersistence($persistence)
            ->setBranchRunner($runner)
            ->addNodes([new DocumentParallelProcessing(), new InterruptableTextProcessNode(), new ImageProcessNode(), new MergeNode()]);
        $persistence = new InMemoryPersistence();

        $suspended = $make($persistence)->run();
        $completed = $make($persistence)->run(ExecutionRequest::resume(['approved' => true]));

        $this->assertTrue($suspended->isInterrupted());
        $this->assertFalse($suspended->has('text_node_executed'));
        $this->assertFalse($completed->has('text_node_executed'));
        $this->assertFalse($completed->has('text_node_resumed'));
        $this->assertSame(['text' => 'TEXT_APPROVED', 'image' => 'processed_image.jpg'], $completed->get('analysis'));
    }

    #[DataProvider('branchRunners')]
    public function test_a_branch_runs_a_revisited_node_again(BranchRunner $runner): void
    {
        $trace = (object) ['visits' => 0];
        $revisited = new class ($trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(TextProcessEvent $event, WorkflowState $state): TextProcessEvent|StopEvent
            {
                $this->trace->visits++;
                $state->set('visits', $state->get('visits', 0) + 1);

                return $state->get('visits') < 2 ? new TextProcessEvent() : new StopEvent($state->get('visits'));
            }
        };

        $state = Workflow::make('branch-revisit')
            ->setSerializer(new ReplayBudgetSerializer())
            ->setBranchRunner($runner)
            ->addNodes([new DocumentParallelProcessing(), $revisited, new ImageProcessNode(), new MergeNode()])
            ->run();

        $this->assertSame(2, $trace->visits);
        $this->assertSame(['text' => 2, 'image' => 'processed_image.jpg'], $state->get('analysis'));
    }

    public function test_a_branch_held_back_by_a_sibling_interruption_never_starts(): void
    {
        $observer = new RecordingObserver();

        Workflow::make('held-back-branch')
            ->observe($observer)
            ->addNodes([new DocumentParallelProcessing(), new InterruptableTextProcessNode(), new ImageProcessNode(), new MergeNode()])
            ->run();

        $this->assertSame([
            ['event' => 'branch-start', 'branchId' => 'text'],
            ['event' => 'branch-end', 'branchId' => 'text'],
        ], array_values(array_filter(
            $observer->recorded,
            fn (array $record): bool => in_array($record['event'], ['branch-start', 'branch-end'], true),
        )));
    }
}
