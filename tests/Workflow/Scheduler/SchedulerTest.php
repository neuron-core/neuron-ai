<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Scheduler;

use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Scheduler\Stubs\SpyScheduler;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Tests\Workflow\Stubs\WaitForEventNode;
use NeuronAI\Workflow\Executor\Scheduler\NullScheduler;
use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
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
        $nullScheduler->onResume('sched-null', $state->getInterruptRequest());
        $nullScheduler->onComplete('sched-null');
        $this->addToAssertionCount(3); // all three hooks accept their input without error
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

    public function testOnResumeFiresOnInlineResume(): void
    {
        // The core contract: an inline resume (the idiomatic approval UX —
        // Workflow::make(resumeToken:)->run($req)) must notify the scheduler so it
        // can cancel the wakeup. Without this, attaching a scheduler would leak
        // registrations on every inline resume.
        $spy = new SpyScheduler();
        $executor = $this->createExecutor(null, $spy);
        $token = 'sched-resume';

        // Run 1: suspends on the wait-for-event. Only onSuspend fires.
        $workflow = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);
        $state = $this->execute($workflow, $executor);

        $this->assertTrue($state->isInterrupted());
        $this->assertCount(1, $spy->onSuspendCalls);
        $this->assertSame([], $spy->onResumeCalls);
        $this->assertSame([], $spy->onCompleteCalls);

        // Resume inline: same token + persistence, request passed to run(). The
        // executor hands the exact request object to onResume, so we compare the
        // whole recorded list by structure + identity (and avoid any offset access
        // on the recorded arrays).
        $resumed = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);
        $resumeRequest = new WaitForEventRequest('user.signup', payload: ['id' => 7]);
        $state = $this->execute($resumed, $executor, $resumeRequest);

        // onResume fired (inline resume satisfied the wait), then onComplete fired
        // because the workflow ran to terminal.
        $this->assertFalse($state->isInterrupted());
        $this->assertSame(
            [['workflowId' => $token, 'request' => $resumeRequest]],
            $spy->onResumeCalls,
        );
        $this->assertSame([$token], $spy->onCompleteCalls);
    }

    public function testOnCompleteFiresOnCleanTerminal(): void
    {
        // A workflow that runs straight to completion: no suspend, no resume, but
        // onComplete fires so the scheduler can drop all coordination state.
        $spy = new SpyScheduler();
        $workflow = Workflow::make(resumeToken: 'sched-complete-2')
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $this->execute($workflow, $this->createExecutor(null, $spy));

        $this->assertSame([], $spy->onSuspendCalls);
        $this->assertSame([], $spy->onResumeCalls);
        $this->assertSame(['sched-complete-2'], $spy->onCompleteCalls);
    }

    public function testOnResumeNotFiredWithoutInterrupt(): void
    {
        // onResume must fire ONLY on a deliberate resume (a non-null interrupt),
        // not on a re-run that replays cached steps — e.g. a crash-recovery retry
        // where the caller passes no resume payload.
        $spy = new SpyScheduler();
        $executor = $this->createExecutor(null, $spy);
        $token = 'sched-no-resume';

        $workflow = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);
        $state = $this->execute($workflow, $executor);
        $this->assertTrue($state->isInterrupted());
        $this->assertSame([], $spy->onResumeCalls);

        // Re-run with NO interrupt: the workflow is still suspended, so it
        // re-suspends (onSuspend fires again). onResume must stay empty.
        $rerun = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);
        $state = $this->execute($rerun, $executor);

        $this->assertTrue($state->isInterrupted());
        $this->assertSame([], $spy->onResumeCalls, 'onResume must fire only on a deliberate resume');
        $this->assertCount(2, $spy->onSuspendCalls);
    }
}
