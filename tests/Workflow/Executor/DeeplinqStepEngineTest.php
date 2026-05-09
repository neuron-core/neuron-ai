<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use Deeplinq\Context;
use Deeplinq\Event as DeeplinqEvent;
use Deeplinq\Step;
use Deeplinq\StepPendingException;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\DeeplinqStepEngine;
use NeuronAI\Workflow\Executor\DeeplinqTaskHandler;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DeeplinqStepEngineTest extends TestCase
{
    /**
     * Simulate the Deeplinq platform replay loop.
     *
     * Step::run() executes the callback on first encounter, records the result,
     * then throws StepPendingException. On replay (with memoized data), it returns
     * the cached result immediately. This helper replays until the workflow completes.
     */
    private function replayUntilComplete(callable $workflowFactory): WorkflowState
    {
        $memoized = [];

        for ($i = 0; $i < 20; $i++) {
            $step = new Step($memoized);
            $context = new Context(
                event: new DeeplinqEvent(name: 'test/trigger'),
                step: $step,
                runId: 'test-run-' . $i,
                attempt: 0,
            );

            $workflow = $workflowFactory();
            $handler = new DeeplinqTaskHandler($workflow);

            try {
                $gen = $handler($context);
                foreach ($gen as $_) {
                }
                return $gen->getReturn();
            } catch (StepPendingException) {
                foreach ($step->getOps() as $op) {
                    if (isset($op['data'])) {
                        $memoized[$op['id']] = ['data' => $op['data']];
                    }
                }
            }
        }

        $this->fail('Workflow did not complete within 20 replays');
    }

    public function testLinearWorkflowCompletesViaReplay(): void
    {
        $factory = fn (): Workflow => Workflow::make()
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $result = $this->replayUntilComplete($factory);

        $this->assertTrue($result->get('node_one_executed'));
        $this->assertTrue($result->get('node_two_executed'));
        $this->assertTrue($result->get('node_three_executed'));
    }

    public function testPackUnpackRoundTrip(): void
    {
        $context = new Context(
            event: new DeeplinqEvent(name: 'test'),
            step: new Step([]),
            runId: 'test',
            attempt: 0,
        );

        $engine = new DeeplinqStepEngine($context, 'test-workflow');

        $event = new StopEvent('test');
        $state = new WorkflowState();
        $state->set('key', 'value');

        $stepResult = new \NeuronAI\Workflow\Executor\StepResult(
            stepId: 'test',
            event: $event,
            state: $state,
        );

        $pack = new ReflectionMethod($engine, 'packStepResult');
        $packed = $pack->invoke($engine, $stepResult);

        $unpack = new ReflectionMethod($engine, 'unpackToStepResult');
        $restored = $unpack->invoke($engine, $packed);

        $this->assertEquals($event, $restored->getEvent());
        $this->assertSame('value', $restored->getState()?->get('key'));
    }
}
