<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Executor\Stub\ContinuationNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageFirstForkNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\InterruptableBranchProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\InterruptableStep1TextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\InterruptableStep2TextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\InterruptableTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\LinearInterruptNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeWithContinuationNode;
use NeuronAI\Tests\Workflow\Executor\Stub\SummaryProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ThreeBranchImageFirstForkNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ThreeBranchMergeNode;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\ResumeInputResult;
use NeuronAI\Workflow\Interrupt\ResumeInputStatus;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use stdClass;
use function array_keys;
use function array_map;

class ParallelInterruptTest extends TestCase
{
    use ExecutorTestHelpers;

    public function test_multiple_interruptions_can_be_resolved_across_addressed_batches(): void
    {
        $fork = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): Stub\DocumentParallelEvent
            {
                return new Stub\DocumentParallelEvent([
                    'text' => new Stub\TextProcessEvent(),
                    'image' => new Stub\TextProcessEvent(),
                ]);
            }
        };

        $workflow = Workflow::make(workflowId: 'multi-suspension')
            ->addNodes([$fork, new InterruptableTextProcessNode(), new MergeNode()]);

        $first = $workflow->run();
        $this->assertSame(1, $first->getExecutionAttempt());
        $this->assertSame([1, 2], array_keys($first->getInterruptRequests()));

        $partial = $workflow->resume([ResumeInput::event((new ApprovalRequest('test'))->withId(1), [])]);
        $this->assertSame(2, $partial->getExecutionAttempt());
        $this->assertTrue($partial->isInterrupted());
        $this->assertSame([2], array_keys($partial->getInterruptRequests()));

        $completed = $workflow->resume([
            ResumeInput::event((new ApprovalRequest('test'))->withId(1), []),
            ResumeInput::event((new ApprovalRequest('test'))->withId(2), []),
        ]);

        $this->assertFalse($completed->isInterrupted());
        $this->assertSame(3, $completed->getExecutionAttempt());
        $this->assertSame(
            [ResumeInputStatus::Stale, ResumeInputStatus::Accepted],
            array_map(
                fn (ResumeInputResult $result): ResumeInputStatus => $result->status,
                $completed->getInputResults(),
            ),
        );
    }

    public function test_partial_resume_does_not_rerun_unaddressed_interrupts(): void
    {
        foreach ([null, new AsyncExecutor()] as $index => $executor) {
            $counter = new stdClass();
            $counter->runs = 0;

            $fork = new class () extends Node {
                public function __invoke(StartEvent $event, WorkflowState $state): Stub\DocumentParallelEvent
                {
                    return new Stub\DocumentParallelEvent([
                        'text' => new Stub\TextProcessEvent(),
                        'image' => new Stub\TextProcessEvent(),
                    ]);
                }
            };

            $node = new class ($counter) extends Node {
                public function __construct(protected stdClass $counter)
                {
                }

                public function __invoke(Stub\TextProcessEvent $event, WorkflowState $state): StopEvent
                {
                    $this->counter->runs++;
                    $this->interrupt(new ApprovalRequest('approval'));

                    return new StopEvent($state->get('__branchId'));
                }
            };

            $workflow = Workflow::make(workflowId: "selective-resume-{$index}")
                ->addNodes([$fork, $node, new MergeNode()]);
            if ($executor instanceof AsyncExecutor) {
                $workflow->setExecutor($executor);
            }

            $workflow->run();
            $workflow->resume([ResumeInput::event((new ApprovalRequest('test'))->withId(1), [])]);

            $this->assertSame(3, $counter->runs);
        }
    }

    public function test_interrupt_inside_branch_surfaces_request(): void
    {
        $workflow = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $state = $this->execute($workflow);

        // The branch's pause surfaces on the workflow state, and the join node
        // (which runs only after all branches complete) did not execute.
        $this->assertTrue($state->isInterrupted());
        $this->assertSame('text branch needs approval', $state->getInterruptRequest()->getMessage());
        $this->assertFalse($state->has('merge_node_executed'));
    }

    public function test_parallel_resume_completes_all_branches(): void
    {
        // Durability: resume on a fresh executor sharing only persistence.
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $request = $this->execute($workflow, $persistence)->getInterruptRequest();
        $this->assertNotNull($request);

        $resumed = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->resume($resumed, $persistence, []);

        $this->assertFalse($result->isInterrupted());
        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('TEXT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function test_completed_branch_retained_across_interrupt(): void
    {
        // Image branch completes, then the text branch pauses. On resume the
        // already-completed image branch must still contribute its result —
        // proving completed branches are retained, not lost, across the pause.
        $workflow = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new ImageFirstForkNode(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $request = $this->execute($workflow)->getInterruptRequest();
        $this->assertNotNull($request);

        $result = $this->resume($workflow);

        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('TEXT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function test_parallel_resume_continues_past_join_node(): void
    {
        $workflow = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeWithContinuationNode(),
                new ContinuationNode(),
            ]);

        $request = $this->execute($workflow)->getInterruptRequest();
        $this->assertNotNull($request);

        $result = $this->resume($workflow);

        $this->assertTrue($result->get('merge_node_executed'));
        $this->assertTrue($result->get('continuation_node_executed'));
    }

    public function test_parallel_resume_with_three_branches(): void
    {
        $workflow = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new ThreeBranchImageFirstForkNode(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new SummaryProcessNode(),
                new ThreeBranchMergeNode(),
            ]);

        $request = $this->execute($workflow)->getInterruptRequest();
        $this->assertNotNull($request);

        $result = $this->resume($workflow);

        $this->assertTrue($result->get('merge_node_executed'));
        $mergeResults = $result->get('merge_results');
        $this->assertSame('TEXT_APPROVED', $mergeResults['text']);
        $this->assertSame('processed_image.jpg', $mergeResults['image']);
        $this->assertSame('SUMMARY', $mergeResults['summary']);
    }

    public function test_re_interrupt_in_resumed_branch(): void
    {
        $workflow = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableStep1TextProcessNode(),
                new InterruptableStep2TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        // First pause: step1 approval
        $request1 = $this->execute($workflow)->getInterruptRequest();
        $this->assertNotNull($request1);
        $this->assertSame('step1 approval', $request1->getMessage());

        // Resuming re-enters the branch and pauses again at step2
        $request2 = $this->resume($workflow)->getInterruptRequest();
        $this->assertNotNull($request2);
        $this->assertSame('step2 approval', $request2->getMessage());

        $result = $this->resume($workflow);
        $this->assertFalse($result->isInterrupted());
        $this->assertTrue($result->get('merge_node_executed'));
        $this->assertSame('TWO_STEP_APPROVED', $result->get('analysis')['text']);
    }

    public function test_linear_interrupt_surfaces_and_resumes(): void
    {
        // A non-parallel interrupt behaves the same way: surfaces on the state,
        // resumes through the request. (No parallel-specific metadata exists.)
        $workflow = Workflow::make(workflowId: 'test-linear-token')
            ->addNodes([new LinearInterruptNode()]);

        $request = $this->execute($workflow)->getInterruptRequest();
        $this->assertNotNull($request);
        $this->assertSame('linear interrupt', $request->getMessage());

        $result = $this->resume($workflow);
        $this->assertFalse($result->isInterrupted());
    }

    public function test_async_parallel_interrupt_surfaces_request(): void
    {
        $workflow = Workflow::make(workflowId: 'test-async-token')
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $state = $this->execute($workflow);

        $this->assertTrue($state->isInterrupted());
        $this->assertSame('text branch needs approval', $state->getInterruptRequest()->getMessage());
    }

    public function test_async_parallel_resume_completes_all_branches(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(workflowId: 'test-async-token')
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $request = $this->execute($workflow, $persistence)->getInterruptRequest();
        $this->assertNotNull($request);

        $resumed = Workflow::make(workflowId: 'test-async-token')
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->resume($resumed, $persistence, []);

        $this->assertFalse($result->isInterrupted());
        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('TEXT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }
}
