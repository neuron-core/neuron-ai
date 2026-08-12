<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Scheduler;

use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Scheduler\Stubs\SpyScheduler;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Tests\Workflow\Stubs\WaitForEventNode;
use NeuronAI\Workflow\Executor\NullScheduler;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

class SchedulerTest extends TestCase
{
    use ExecutorTestHelpers;

    public function testSchedulerHookFiresOnPause(): void
    {
        $spy = new SpyScheduler();
        $workflow = Workflow::make(address: 'sched-pause')
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $this->execute($workflow, null, $spy);

        $this->assertCount(1, $spy->onSuspendCalls);
        $call = $spy->onSuspendCalls[0];
        $this->assertSame('sched-pause', $call['address']);
        // The wakeup is generation-stamped so the waking side can discard it
        // when the address has moved on to another run.
        $this->assertSame($workflow->getRunId(), $call['runId']);
        $this->assertSame(InterruptType::WaitForEvent, $call['request']->type());
    }

    public function testSchedulerHookDoesNotFireOnCompletion(): void
    {
        $spy = new SpyScheduler();
        // A workflow that runs straight to completion (no interrupt).
        $workflow = Workflow::make(address: 'sched-complete')
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $this->execute($workflow, null, $spy);

        $this->assertSame([], $spy->onSuspendCalls);
    }

    public function testNullSchedulerIsInert(): void
    {
        // NullScheduler is the default and must not affect pause/resume behavior.
        $nullScheduler = new NullScheduler();
        $workflow = Workflow::make(address: 'sched-null')
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        // No exception, no side effects — just records the pause on the state.
        $state = $this->execute($workflow, null, $nullScheduler);

        $this->assertTrue($state->isInterrupted());

        $nullScheduler->onSuspend('sched-null', (string) $workflow->getRunId(), $state->getInterruptRequest());
        $nullScheduler->onResume('sched-null');
        $nullScheduler->onComplete('sched-null');
        $this->addToAssertionCount(3); // all three hooks accept their input without error
    }

    public function testCustomSchedulerIsInjectedViaWorkflow(): void
    {
        // setScheduler() must reach the executor via getExecutor().
        $spy = new SpyScheduler();
        $workflow = Workflow::make(address: 'sched-inject')
            ->setScheduler($spy)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $workflow->run();

        $this->assertCount(1, $spy->onSuspendCalls);
        $this->assertSame('sched-inject', $spy->onSuspendCalls[0]['address']);
    }

    public function testOnResumeFiresOnInlineResume(): void
    {
        // The core contract: an inline resume (Workflow::make(address:)->resume($payload))
        // must notify the scheduler so it can cancel the wakeup. Without this, attaching
        // a scheduler would leak registrations on every inline resume.
        $spy = new SpyScheduler();
        $persistence = new InMemoryPersistence();
        $token = 'sched-resume';

        // Run 1: suspends on the wait-for-event. Only onSuspend fires.
        $workflow = Workflow::make(address: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);
        $state = $this->execute($workflow, $persistence, $spy);

        $this->assertTrue($state->isInterrupted());
        $this->assertCount(1, $spy->onSuspendCalls);
        $this->assertSame([], $spy->onResumeCalls);
        $this->assertSame([], $spy->onCompleteCalls);

        // Resume inline: same token + persistence, delivering the payload. The executor
        // fires onResume with the workflow id (it cancels by id, not by request).
        $resumed = Workflow::make(address: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);
        $state = $this->resume($resumed, $persistence, ['id' => 7], scheduler: $spy);

        // onResume fired (inline resume satisfied the wait), then onComplete fired
        // because the workflow ran to terminal.
        $this->assertFalse($state->isInterrupted());
        $this->assertSame([$token], $spy->onResumeCalls);
        $this->assertSame([$token], $spy->onCompleteCalls);
    }

    public function testOnCompleteFiresOnCleanTerminal(): void
    {
        // A workflow that runs straight to completion: no suspend, no resume, but
        // onComplete fires so the scheduler can drop all coordination state.
        $spy = new SpyScheduler();
        $workflow = Workflow::make(address: 'sched-complete-2')
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $this->execute($workflow, null, $spy);

        $this->assertSame([], $spy->onSuspendCalls);
        $this->assertSame([], $spy->onResumeCalls);
        $this->assertSame(['sched-complete-2'], $spy->onCompleteCalls);
    }

    public function testOnResumeNotFiredWithoutInterrupt(): void
    {
        // onResume must fire ONLY when a payload is delivered, not on a bare
        // revive that replays cached steps — e.g. a crash-recovery retry
        // where the caller delivers nothing.
        $spy = new SpyScheduler();
        $persistence = new InMemoryPersistence();
        $token = 'sched-no-resume';

        $workflow = Workflow::make(address: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);
        $state = $this->execute($workflow, $persistence, $spy);
        $this->assertTrue($state->isInterrupted());
        $this->assertSame([], $spy->onResumeCalls);

        // Revive with NO payload: the workflow is still suspended, so it
        // re-suspends (onSuspend fires again). onResume must stay empty.
        $rerun = Workflow::make(address: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);
        $state = $this->resume($rerun, $persistence, null, false, $spy);

        $this->assertTrue($state->isInterrupted());
        $this->assertSame([], $spy->onResumeCalls, 'onResume must fire only on a deliberate resume');
        $this->assertCount(2, $spy->onSuspendCalls);
    }
}
