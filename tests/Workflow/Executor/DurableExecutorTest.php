<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use Flowline\Step;
use Flowline\StepPendingException;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Executor\DurableExecutor;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class DurableExecutorTest extends TestCase
{
    private function createWorkflow(): Workflow
    {
        return Workflow::make()->addNodes([
            new NodeOne(),
            new NodeTwo(),
            new NodeThree(),
        ]);
    }

    public function testFirstCallbackExecutesOnlyFirstNode(): void
    {
        $step = new Step([]);
        $executor = new DurableExecutor($step);

        $caught = false;
        try {
            $executor->executeDurable($this->createWorkflow());
        } catch (StepPendingException) {
            $caught = true;
        }

        Assert::assertTrue($caught, 'First callback should throw StepPendingException');

        $ops = $step->getOps();
        Assert::assertCount(1, $ops, 'First callback should record exactly one step op');
        Assert::assertSame('StepRun', $ops[0]['op']);

        // Verify step-0 data contains the first node's output event
        $data = $ops[0]['data'];
        Assert::assertArrayHasKey('event_type', $data);
        Assert::assertArrayHasKey('event_blob', $data);
        Assert::assertArrayHasKey('state_blob', $data);

        // The state should contain node_one_executed
        $state = unserialize(base64_decode($data['state_blob']));
        Assert::assertTrue($state->get('node_one_executed'));
        Assert::assertFalse($state->has('node_two_executed'));
        Assert::assertFalse($state->has('node_three_executed'));
    }

    public function testSecondCallbackReplaysFirstAndExecutesSecond(): void
    {
        // Simulate first callback
        $step1 = new Step([]);
        $executor1 = new DurableExecutor($step1);
        try {
            $executor1->executeDurable($this->createWorkflow());
        } catch (StepPendingException) {
        }
        $ops1 = $step1->getOps();
        $step0Data = $ops1[0]['data'];

        // Second callback: memoized step-0, execute step-1
        $memoized = [
            sha1('step-0') => ['data' => $step0Data],
        ];
        $step2 = new Step($memoized);
        $executor2 = new DurableExecutor($step2);

        $caught = false;
        try {
            $executor2->executeDurable($this->createWorkflow());
        } catch (StepPendingException) {
            $caught = true;
        }

        Assert::assertTrue($caught, 'Second callback should throw StepPendingException');

        $ops2 = $step2->getOps();
        Assert::assertCount(1, $ops2, 'Second callback should record exactly one new step op');

        $data = $ops2[0]['data'];
        $state = unserialize(base64_decode($data['state_blob']));
        Assert::assertTrue($state->get('node_one_executed'));
        Assert::assertTrue($state->get('node_two_executed'));
        Assert::assertFalse($state->has('node_three_executed'));

        // Event data flows between nodes
        Assert::assertEquals('First complete', $state->get('first_message'));
    }

    public function testFinalCallbackReturnsResult(): void
    {
        // Callback 1: execute step-0 (NodeOne)
        $step1 = new Step([]);
        $executor1 = new DurableExecutor($step1);
        try {
            $executor1->executeDurable($this->createWorkflow());
        } catch (StepPendingException) {
        }
        $step0Data = $step1->getOps()[0]['data'];

        // Callback 2: memoized step-0, execute step-1 (NodeTwo)
        $memoized2 = [sha1('step-0') => ['data' => $step0Data]];
        $step2 = new Step($memoized2);
        $executor2 = new DurableExecutor($step2);
        try {
            $executor2->executeDurable($this->createWorkflow());
        } catch (StepPendingException) {
        }
        $step1Data = $step2->getOps()[0]['data'];

        // Callback 3: memoized step-0,1, execute step-2 (NodeThree)
        $memoized3 = [
            sha1('step-0') => ['data' => $step0Data],
            sha1('step-1') => ['data' => $step1Data],
        ];
        $step3 = new Step($memoized3);
        $executor3 = new DurableExecutor($step3);
        try {
            $executor3->executeDurable($this->createWorkflow());
        } catch (StepPendingException) {
        }
        $step2Data = $step3->getOps()[0]['data'];

        // Callback 4: all memoized, handler returns the result
        $memoized4 = [
            sha1('step-0') => ['data' => $step0Data],
            sha1('step-1') => ['data' => $step1Data],
            sha1('step-2') => ['data' => $step2Data],
        ];
        $step4 = new Step($memoized4);
        $executor4 = new DurableExecutor($step4);

        $result = $executor4->executeDurable($this->createWorkflow());

        // StopEvent result
        Assert::assertEquals('Workflow complete', $result);
    }

    public function testStateAccumulatesAcrossAllCallbacks(): void
    {
        // Run all callbacks, capturing state at each point
        $step1 = new Step([]);
        $executor1 = new DurableExecutor($step1);
        try {
            $executor1->executeDurable($this->createWorkflow());
        } catch (StepPendingException) {
        }
        $step0Data = $step1->getOps()[0]['data'];

        $memoized2 = [sha1('step-0') => ['data' => $step0Data]];
        $step2 = new Step($memoized2);
        $executor2 = new DurableExecutor($step2);
        try {
            $executor2->executeDurable($this->createWorkflow());
        } catch (StepPendingException) {
        }
        $step1Data = $step2->getOps()[0]['data'];

        $memoized3 = [
            sha1('step-0') => ['data' => $step0Data],
            sha1('step-1') => ['data' => $step1Data],
        ];
        $step3 = new Step($memoized3);
        $executor3 = new DurableExecutor($step3);
        try {
            $executor3->executeDurable($this->createWorkflow());
        } catch (StepPendingException) {
        }
        $step2Data = $step3->getOps()[0]['data'];

        // Verify final state through the last step's data
        $finalState = unserialize(base64_decode($step2Data['state_blob']));
        Assert::assertTrue($finalState->get('node_one_executed'));
        Assert::assertTrue($finalState->get('node_two_executed'));
        Assert::assertTrue($finalState->get('node_three_executed'));
        Assert::assertEquals('First complete', $finalState->get('first_message'));
        Assert::assertEquals('Second complete', $finalState->get('second_message'));
    }
}
