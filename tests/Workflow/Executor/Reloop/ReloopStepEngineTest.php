<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Reloop;

use NeuronAI\Tests\Workflow\Executor\Stubs\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\TextProcessNode;
use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\Amp\AsyncExecutor;
use NeuronAI\Workflow\Executor\Reloop\ReloopStepEngine;
use NeuronAI\Workflow\Executor\Reloop\ReloopWorkflowHandler;
use NeuronAI\Workflow\Executor\StepResult;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Reloop\Context;
use Reloop\Event;
use Reloop\Step;

class ReloopStepEngineTest extends TestCase
{
    use ReplaysReloopSteps;

    public function testLinearWorkflowCompletesViaReplay(): void
    {
        $workflow = Workflow::make()
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $this->replayWithReloop($workflow);

        $state = $workflow->resolveState();
        $this->assertTrue($state->get('node_one_executed'));
        $this->assertTrue($state->get('node_two_executed'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function testPackUnpackRoundTrip(): void
    {
        $context = new Context(
            event: new Event(name: 'test'),
            step: new Step([]),
            runId: 'test',
            attempt: 0,
        );

        $engine = new ReloopStepEngine($context);

        $event = new StopEvent('test');
        $state = new WorkflowState();
        $state->set('key', 'value');

        $stepResult = new StepResult(
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

    public function testParallelWorkflowWithAsyncExecutorCompletesViaReplay(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $this->replayWithReloop(
            $workflow,
            executorFactory: fn (ReloopStepEngine $engine): AsyncExecutor => new AsyncExecutor($engine),
        );

        $analysis = $workflow->resolveState()->get('analysis');
        $this->assertSame('HELLO', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function testInterruptResumeCompletesViaReplay(): void
    {
        $workflow = Workflow::make()
            ->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]);

        $this->replayWithReloop(
            $workflow,
            resumeRequest: new ApprovalRequest('approved'),
        );

        $state = $workflow->resolveState();
        $this->assertTrue($state->get('node_one_executed'));
        $this->assertTrue($state->get('interruptable_node_executed'));
        $this->assertSame('completed', $state->get('received_feedback'));
        $this->assertTrue($state->get('node_three_executed'));
    }
}
