<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Executor\Stub\ContinuationEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeWithContinuationNode;
use NeuronAI\Tests\Workflow\Executor\Stub\RetryingTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\StatefulTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ThreeBranchMergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ThreeBranchParallelEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

class DurableBranchTest extends TestCase
{
    use ExecutorTestHelpers;

    /** @dataProvider branchExecutorProvider */
    public function test_branch_replay_restores_committed_state(bool $async): void
    {
        $persistence = new InMemoryPersistence();
        StatefulTextProcessNode::$executions = 0;
        $make = function (bool $fail) use ($persistence, $async): Workflow {
            $workflow = Workflow::make('branch-state')->setPersistence($persistence)->addNodes([
                new DocumentParallelProcessing(),
                new StatefulTextProcessNode(),
                new RetryingTextProcessNode($fail),
                new ImageProcessNode(),
                new MergeNode(),
            ]);
            if ($async) {
                $workflow->setExecutor(new AsyncExecutor());
            }

            return $workflow;
        };

        try {
            $make(true)->run();
            self::fail('Expected the branch to fail.');
        } catch (RuntimeException $e) {
            self::assertSame('Branch failed after its state was persisted.', $e->getMessage());
        }

        $state = $make(false)->run([]);

        self::assertSame(42, $state->get('analysis')['text']);
        self::assertFalse($state->has('branch_value'));
        self::assertSame(1, StatefulTextProcessNode::$executions);
    }

    /** @return array<string, array{bool}> */
    public static function branchExecutorProvider(): array
    {
        return ['sequential' => [false], 'async' => [true]];
    }

    public function test_parallel_branch_with_step_engine_completes_all_branches(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->execute($workflow, $persistence);

        $analysis = $result->get('analysis');
        $this->assertSame('HELLO', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function test_main_flow_with_step_engine(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->execute($workflow, $persistence);

        $analysis = $result->get('analysis');
        $this->assertSame('HELLO', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function test_separate_parallel_forks_do_not_share_branch_steps(): void
    {
        foreach ([null, new AsyncExecutor()] as $executor) {
            $counter = new stdClass();
            $counter->runs = 0;
            $firstFork = new class () extends Node {
                public function __invoke(StartEvent $event, WorkflowState $state): DocumentParallelEvent
                {
                    return new DocumentParallelEvent(['same' => new TextProcessEvent()]);
                }
            };
            $secondFork = new class () extends Node {
                public function __invoke(ContinuationEvent $event, WorkflowState $state): ThreeBranchParallelEvent
                {
                    return new ThreeBranchParallelEvent(['same' => new TextProcessEvent()]);
                }
            };
            $branch = new class ($counter) extends Node {
                public function __construct(protected stdClass $counter)
                {
                }

                public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
                {
                    return new StopEvent('result-' . ++$this->counter->runs);
                }
            };

            $workflow = Workflow::make()->addNodes([
                $firstFork,
                $branch,
                new MergeWithContinuationNode(),
                $secondFork,
                new ThreeBranchMergeNode(),
            ]);
            if ($executor instanceof AsyncExecutor) {
                $workflow->setExecutor($executor);
            }

            $state = $workflow->run();

            $this->assertSame(2, $counter->runs);
            $this->assertSame('result-2', $state->get('merge_results')['same']);
        }
    }
}
