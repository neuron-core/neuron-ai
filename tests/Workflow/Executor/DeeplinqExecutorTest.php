<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use Deeplinq\Context;
use Deeplinq\Event as DeeplinqEvent;
use Deeplinq\Step;
use Deeplinq\StepPendingException;
use NeuronAI\Tests\Workflow\Executor\Stubs\CountableNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableInterruptNodeB;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableNodeA;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableNodeB;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableNodeC;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\InterruptableBranchProcessing;
use NeuronAI\Tests\Workflow\Executor\Stubs\InterruptableTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\TextProcessNode;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\DeeplinqExecutor;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function base64_decode;
use function base64_encode;
use function serialize;
use function sha1;
use function unserialize;

class DeeplinqExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        CountableNode::resetExecutionCount();
    }

    protected function createContext(Step $step): Context
    {
        return new Context(
            event: new DeeplinqEvent(name: 'test/trigger'),
            step: $step,
            runId: 'test-run',
            attempt: 0,
        );
    }

    /**
     * Simulate the Deeplinq platform's replay mechanism.
     * Each iteration is one HTTP round-trip. Ops from each call
     * accumulate into memoized data for the next call.
     */
    protected function runToCompletion(DeeplinqExecutor $executor): WorkflowState
    {
        $memoized = [];

        for ($i = 0; $i < 20; $i++) {
            $step = new Step($memoized);

            try {
                return $executor($this->createContext($step));
            } catch (StepPendingException) {
                $memoized = $this->accumulateOps($step, $memoized);
            }
        }

        throw new RuntimeException('Workflow did not complete within 20 iterations');
    }

    /**
     * Execute one HTTP round-trip, catching StepPendingException.
     * Returns the Step so tests can inspect ops and build memoized data.
     */
    protected function executeOneRound(DeeplinqExecutor $executor, array $memoized = []): Step
    {
        $step = new Step($memoized);
        try {
            $executor($this->createContext($step));
        } catch (StepPendingException) {
        }
        return $step;
    }

    /**
     * Accumulate StepRun ops into memoized data format for the Deeplinq Step.
     */
    protected function accumulateOps(Step $step, array $memoized = []): array
    {
        foreach ($step->getOps() as $op) {
            if (isset($op['data'])) {
                $memoized[$op['id']] = ['data' => $op['data']];
            }
        }
        return $memoized;
    }

    // ---------------------------------------------------------------
    //  Basic traversal
    // ---------------------------------------------------------------

    public function testThreeNodeWorkflowCompletes(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        $executor = new DeeplinqExecutor($workflow);
        $state = $this->runToCompletion($executor);

        $this->assertTrue($state->get('step_a_executed'));
        $this->assertTrue($state->get('step_b_executed'));
        $this->assertTrue($state->get('step_c_executed'));
    }

    public function testStateSurvivesAcrossRoundTrips(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        $executor = new DeeplinqExecutor($workflow);
        $memoized = [];

        // Call 1: NodeA executes (new), throws StepPendingException
        $step = $this->executeOneRound($executor, $memoized);
        $this->assertCount(1, $step->getOps());
        $this->assertSame('StepRun', $step->getOps()[0]['op']);
        $memoized = $this->accumulateOps($step, $memoized);

        // Call 2: NodeA memoized, NodeB executes (new)
        $step = $this->executeOneRound($executor, $memoized);
        $this->assertCount(1, $step->getOps());
        $memoized = $this->accumulateOps($step, $memoized);

        // Verify the packed result from NodeA contains state
        $nodeAOp = (new Step($memoized))->getOps();
        // NodeA's packed state should include step_a_executed
        $packedNodeA = $memoized[sha1(DurableNodeA::class)]['data'];
        $unpackedState = unserialize(base64_decode($packedNodeA['state']));
        $this->assertTrue($unpackedState->get('step_a_executed'));

        // Call 3: NodeA + NodeB memoized, NodeC executes (new)
        $step = $this->executeOneRound($executor, $memoized);
        $memoized = $this->accumulateOps($step, $memoized);

        // Call 4: All memoized, workflow completes
        $state = $executor($this->createContext(new Step($memoized)));

        $this->assertTrue($state->get('step_a_executed'));
        $this->assertTrue($state->get('step_b_executed'));
        $this->assertTrue($state->get('step_c_executed'));
    }

    public function testMemoizedStepsAreNotReExecuted(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        $executor = new DeeplinqExecutor($workflow);
        $memoized = [];

        // Call 1: Only NodeA executes
        $step = $this->executeOneRound($executor, $memoized);
        $this->assertSame(1, CountableNode::getExecutionCount());
        $memoized = $this->accumulateOps($step);

        // Call 2: NodeA memoized (skipped), NodeB executes
        CountableNode::resetExecutionCount();
        $step = $this->executeOneRound($executor, $memoized);
        $this->assertSame(1, CountableNode::getExecutionCount());
        $memoized = $this->accumulateOps($step, $memoized);

        // Call 3: NodeA + NodeB memoized (skipped), NodeC executes
        CountableNode::resetExecutionCount();
        $step = $this->executeOneRound($executor, $memoized);
        $this->assertSame(1, CountableNode::getExecutionCount());
    }

    // ---------------------------------------------------------------
    //  Interrupt / Resume (3-step pattern)
    // ---------------------------------------------------------------

    public function testInterruptFlowResumesCorrectly(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DurableNodeA(),
                new DurableInterruptNodeB(),
                new DurableNodeC(),
            ]);

        $executor = new DeeplinqExecutor($workflow);
        $memoized = [];

        // Call 1: NodeA executes
        $step = $this->executeOneRound($executor, $memoized);
        $this->assertSame(1, CountableNode::getExecutionCount());
        $memoized = $this->accumulateOps($step, $memoized);

        // Call 2: NodeA memoized, InterruptNodeB executes and interrupts
        CountableNode::resetExecutionCount();
        $step = $this->executeOneRound($executor, $memoized);
        $this->assertSame(1, CountableNode::getExecutionCount());
        $memoized = $this->accumulateOps($step, $memoized);

        // Verify the interrupt marker was recorded
        $packedInterrupt = $step->getOps()[0]['data'];
        $this->assertTrue($packedInterrupt['interrupted'] ?? false);

        // Call 3: Interrupt marker memoized, waitForEvent discovered
        CountableNode::resetExecutionCount();
        $step = $this->executeOneRound($executor, $memoized);
        $this->assertSame(0, CountableNode::getExecutionCount());
        $ops = $step->getOps();
        $this->assertSame('WaitForEvent', $ops[0]['op']);

        // Simulate the platform delivering the user's resume event
        $resumeRequest = new ApprovalRequest('resume', [new Action('approve_b', 'Approve', 'Approve')]);
        $resumeRequest->getAction('approve_b')->approve();
        $memoized[$ops[0]['id']] = ['data' => base64_encode(serialize($resumeRequest))];

        // Call 4: waitForEvent returns resume data, InterruptNodeB.resumed executes
        CountableNode::resetExecutionCount();
        $step = $this->executeOneRound($executor, $memoized);
        $this->assertSame(1, CountableNode::getExecutionCount());
        $memoized = $this->accumulateOps($step, $memoized);

        // Call 5: All previous memoized, NodeC executes
        CountableNode::resetExecutionCount();
        $step = $this->executeOneRound($executor, $memoized);
        $this->assertSame(1, CountableNode::getExecutionCount());
        $memoized = $this->accumulateOps($step, $memoized);

        // Call 6: All memoized, workflow completes
        $state = $executor($this->createContext(new Step($memoized)));

        $this->assertTrue($state->get('step_a_executed'));
        $this->assertTrue($state->get('step_b_executed'));
        $this->assertTrue($state->get('step_b_resumed'));
        $this->assertTrue($state->get('step_c_executed'));
    }

    // ---------------------------------------------------------------
    //  Parallel branches
    // ---------------------------------------------------------------

    public function testParallelBranchesExecuteAndMerge(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $executor = new DeeplinqExecutor($workflow);
        $state = $this->runToCompletion($executor);

        $this->assertTrue($state->get('merge_node_executed'));
        $this->assertSame('HELLO', $state->get('analysis')['text']);
        $this->assertSame('processed_image.jpg', $state->get('analysis')['image']);
    }

    public function testParallelBranchInterruptResumes(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $executor = new DeeplinqExecutor($workflow);
        $memoized = [];

        // Replay until we hit the waitForEvent for the branch interrupt
        for ($i = 0; $i < 20; $i++) {
            $step = new Step($memoized);
            try {
                $state = $executor($this->createContext($step));
                // If we reach here, workflow completed
                $this->assertTrue($state->get('merge_node_executed'));
                $this->assertSame('TEXT_APPROVED', $state->get('analysis')['text']);
                $this->assertSame('processed_image.jpg', $state->get('analysis')['image']);
                return;
            } catch (StepPendingException) {
                $ops = $step->getOps();
                $foundWaitForEvent = false;
                foreach ($ops as $op) {
                    if (isset($op['data'])) {
                        $memoized[$op['id']] = ['data' => $op['data']];
                    }
                    if ($op['op'] === 'WaitForEvent') {
                        // Inject resume data for the branch interrupt
                        $resumeRequest = new ApprovalRequest(
                            'approve text',
                            [new Action('approve_text', 'Approve', 'Approve text processing')],
                        );
                        $resumeRequest->getAction('approve_text')->approve();
                        $memoized[$op['id']] = ['data' => base64_encode(serialize($resumeRequest))];
                        $foundWaitForEvent = true;
                    }
                }
            }
        }

        $this->fail('Workflow did not complete within 20 iterations');
    }

    // ---------------------------------------------------------------
    //  Error handling
    // ---------------------------------------------------------------

    public function testExceptionPropagatesAsStepPending(): void
    {
        $crashingNodeB = new DurableNodeB();
        $crashingNodeB->setShouldCrash(true);

        $workflow = Workflow::make()
            ->addNodes([
                new DurableNodeA(),
                $crashingNodeB,
                new DurableNodeC(),
            ]);

        $executor = new DeeplinqExecutor($workflow);
        $memoized = [];

        // Call 1: NodeA executes
        $step = $this->executeOneRound($executor, $memoized);
        $memoized = $this->accumulateOps($step);

        // Call 2: NodeA memoized, NodeB crashes
        $step = new Step($memoized);

        try {
            $executor($this->createContext($step));
            $this->fail('Expected StepPendingException was not thrown');
        } catch (StepPendingException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }
    }

    public function testStepErrorRecordedOnCrash(): void
    {
        $crashingNodeB = new DurableNodeB();
        $crashingNodeB->setShouldCrash(true);

        $workflow = Workflow::make()
            ->addNodes([
                new DurableNodeA(),
                $crashingNodeB,
                new DurableNodeC(),
            ]);

        $executor = new DeeplinqExecutor($workflow);
        $memoized = [];

        // Call 1: NodeA executes
        $step = $this->executeOneRound($executor, $memoized);
        $memoized = $this->accumulateOps($step);

        // Call 2: NodeB crashes — SDK records StepError
        $step = new Step($memoized);
        try {
            $executor($this->createContext($step));
        } catch (StepPendingException) {
            $ops = $step->getOps();
            $this->assertCount(1, $ops);
            $this->assertSame('StepError', $ops[0]['op']);
            $this->assertSame(RuntimeException::class, $ops[0]['error']['name']);
            $this->assertStringContainsString('Simulated crash', $ops[0]['error']['message']);
        }
    }

    // ---------------------------------------------------------------
    //  sendResume serialization
    // ---------------------------------------------------------------

    public function testResumeDataSerializationRoundTrip(): void
    {
        $request = new ApprovalRequest('approve this', [new Action('a1', 'Approve', 'Go')]);
        $request->getAction('a1')->approve();

        $serialized = base64_encode(serialize($request));
        $deserialized = unserialize(base64_decode($serialized));

        $this->assertInstanceOf(ApprovalRequest::class, $deserialized);
        $this->assertSame('approve this', $deserialized->getMessage());
        $this->assertTrue($deserialized->getAction('a1')->isApproved());
    }

    // ---------------------------------------------------------------
    //  Packed result format
    // ---------------------------------------------------------------

    public function testPackedResultContainsEventAndState(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        $executor = new DeeplinqExecutor($workflow);

        // Call 1: NodeA executes
        $step = $this->executeOneRound($executor);

        $ops = $step->getOps();
        $this->assertCount(1, $ops);

        $packed = $ops[0]['data'];
        $this->assertArrayHasKey('event', $packed);
        $this->assertArrayHasKey('state', $packed);
        $this->assertArrayNotHasKey('interrupted', $packed);

        // Verify the packed event deserializes to the correct type
        $event = unserialize(base64_decode($packed['event']));
        $this->assertInstanceOf(\NeuronAI\Tests\Workflow\Executor\Stubs\DurableEventA::class, $event);

        // Verify the packed state carries NodeA's mutation
        $state = unserialize(base64_decode($packed['state']));
        $this->assertTrue($state->get('step_a_executed'));
    }
}
