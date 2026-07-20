<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Scheduler;

use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Scheduler\Stubs\SpyScheduler;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\WaitForEventNode;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Scheduler\NullScheduler;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

class SchedulerTest extends TestCase
{
    use ExecutorTestHelpers;

    public function testSchedulerHookFiresOnPause(): void
    {
        $spy = new SpyScheduler();
        $workflow = Workflow::make(resumeToken: 'sched-pause')
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $this->execute($workflow, $this->createExecutor(null, $spy));

        $this->assertCount(1, $spy->onSuspendCalls);
        $call = $spy->onSuspendCalls[0];
        $this->assertSame('sched-pause', $call['workflowId']);
        $this->assertSame(InterruptType::WaitForEvent, $call['request']->type());
    }

    public function testSchedulerHookDoesNotFireOnCompletion(): void
    {
        $spy = new SpyScheduler();
        // A workflow that runs straight to completion (no interrupt).
        $workflow = Workflow::make(resumeToken: 'sched-complete')
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $this->execute($workflow, $this->createExecutor(null, $spy));

        $this->assertSame([], $spy->onSuspendCalls);
    }

    public function testNullSchedulerIsInert(): void
    {
        // NullScheduler is the default and must not affect pause/resume behavior.
        $nullScheduler = new NullScheduler();
        $workflow = Workflow::make(resumeToken: 'sched-null')
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        // No exception, no side effects — just records the pause on the state.
        $state = $this->execute($workflow, $this->createExecutor(null, $nullScheduler));

        $this->assertTrue($state->isInterrupted());

        $nullScheduler->onSuspend('sched-null', $state->getInterruptRequest());
        $this->addToAssertionCount(1); // onSuspend accepts any request without error
    }

    public function testCustomSchedulerIsInjectedViaWorkflow(): void
    {
        // setScheduler() must reach the executor via resolveExecutor().
        $spy = new SpyScheduler();
        $workflow = Workflow::make(resumeToken: 'sched-inject')
            ->setScheduler($spy)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $workflow->run();

        $this->assertCount(1, $spy->onSuspendCalls);
        $this->assertSame('sched-inject', $spy->onSuspendCalls[0]['workflowId']);
    }
}
