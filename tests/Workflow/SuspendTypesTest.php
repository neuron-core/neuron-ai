<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use DateTimeImmutable;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\SleepUntilNode;
use NeuronAI\Tests\Workflow\Stub\WaitForEventNode;
use NeuronAI\Tests\Workflow\Stub\WaitForEventWithTimeoutNode;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use function glob;
use function is_dir;
use function rmdir;
use function serialize;
use function sys_get_temp_dir;
use function unlink;
use function unserialize;
use const DIRECTORY_SEPARATOR;

class SuspendTypesTest extends TestCase
{
    use ExecutorTestHelpers;

    public function test_wait_for_event_pauses_and_resumes(): void
    {
        $persistence = new InMemoryPersistence();
        $token = 'wfe-basic';

        $workflow = Workflow::make(workflowId: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $state = $this->execute($workflow, $persistence);

        // Paused on the wait-for-event; downstream node did not run.
        $this->assertTrue($state->isInterrupted());
        $interrupt = $state->getInterruptRequest();
        $this->assertInstanceOf(WaitForEventRequest::class, $interrupt);
        $this->assertSame(InterruptType::WaitForEvent, $interrupt->type());
        $this->assertSame('user.signup', $interrupt->getEventName());
        $this->assertFalse($state->has('node_three_executed'));

        // Resume on a fresh executor sharing the persistence, delivering the payload.
        $resumed = Workflow::make(workflowId: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $state = $this->resume(
            $resumed,
            $persistence,
            ['id' => 7],
        );

        $this->assertFalse($state->isInterrupted());
        $this->assertSame(['id' => 7], $state->get('received_payload'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function test_named_signal_resumes_matching_event(): void
    {
        $workflow = Workflow::make(workflowId: 'named-signal')
            ->setPersistence(new InMemoryPersistence())
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $workflow->run();
        $state = $workflow->signal('user.signup', ['id' => 7])->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertSame(['id' => 7], $state->get('received_payload'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function test_unmatched_signal_fails_loudly(): void
    {
        $workflow = Workflow::make(workflowId: 'unmatched-signal')
            ->setPersistence(new InMemoryPersistence())
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $workflow->run();

        $this->expectException(\NeuronAI\Exceptions\WorkflowException::class);
        $this->expectExceptionMessage("is waiting for signal 'other.event'");

        $workflow->signal('other.event')->run();
    }

    public function test_signal_to_retained_completed_run_fails_loudly(): void
    {
        $workflow = Workflow::make(workflowId: 'completed-signal')
            ->retainCompletionUntilAcknowledged()
            ->setPersistence(new InMemoryPersistence())
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $workflow->run();
        $workflow->signal('user.signup')->run();

        $this->expectException(\NeuronAI\Exceptions\WorkflowException::class);
        $this->expectExceptionMessage("is waiting for signal 'user.signup'");

        $workflow->signal('user.signup')->run();
    }

    public function test_only_one_signal_can_be_staged(): void
    {
        $workflow = Workflow::make();
        $this->assertSame($workflow, $workflow->signal('first'));

        $this->expectException(\NeuronAI\Exceptions\WorkflowException::class);
        $this->expectExceptionMessage("Signal 'first' is already staged");

        $workflow->signal('second');
    }

    public function test_staged_signal_rejects_explicit_continuation_arguments(): void
    {
        $workflow = Workflow::make(workflowId: 'signal-input-conflict')
            ->setPersistence(new InMemoryPersistence())
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $workflow->run();
        $workflow->signal('user.signup');

        $this->expectException(\NeuronAI\Exceptions\WorkflowException::class);
        $this->expectExceptionMessage('cannot be combined with addressed inputs');

        $workflow->run([]);
    }

    public function test_continuation_fence_requires_explicit_input_array(): void
    {
        $this->expectException(\NeuronAI\Exceptions\WorkflowException::class);
        $this->expectExceptionMessage('require an explicit input array');

        Workflow::make(workflowId: 'explicit-input-fence')->run(expectedRunId: 'run_1');
    }

    public function test_sleep_until_pauses_and_resumes(): void
    {
        $persistence = new InMemoryPersistence();
        $token = 'sleep-basic';

        $workflow = Workflow::make(workflowId: $token)
            ->addNodes([new NodeOne(), new SleepUntilNode(), new NodeThree()]);

        $state = $this->execute($workflow, $persistence);

        $this->assertTrue($state->isInterrupted());
        $interrupt = $state->getInterruptRequest();
        $this->assertInstanceOf(SleepUntilRequest::class, $interrupt);
        $this->assertSame(InterruptType::SleepUntil, $interrupt->type());
        $this->assertFalse($state->has('node_three_executed'));

        // Resume carries no payload — the wakeup itself is the signal (empty payload).
        $resumed = Workflow::make(workflowId: $token)
            ->addNodes([new NodeOne(), new SleepUntilNode(), new NodeThree()]);

        $state = $this->resume(
            $resumed,
            $persistence,
            [],
        );

        $this->assertFalse($state->isInterrupted());
        $this->assertTrue($state->get('sleep_resumed'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function test_resume_kind_must_match_the_interrupt_type(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make(workflowId: 'sleep-kind')
            ->setPersistence($persistence)
            ->addNodes([new NodeOne(), new SleepUntilNode(), new NodeThree()]);

        $workflow->run();

        $this->expectException(\NeuronAI\Exceptions\WorkflowException::class);
        $this->expectExceptionMessage("incompatible with interrupt 1");

        $workflow->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), [])]);
    }

    public function test_wait_for_event_survives_file_persistence_serialization(): void
    {
        // FilePersistence forces real PHP serialization of the active request
        // across the pause/resume boundary.
        $dir = sys_get_temp_dir() . '/neuron_test_wfe_serial';
        $persistence = new FilePersistence($dir);
        $token = 'wfe-serial';

        try {
            $workflow = Workflow::make(workflowId: $token)
                ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

            $request = $this->execute($workflow, $persistence)->getInterruptRequest();
            $this->assertInstanceOf(WaitForEventRequest::class, $request);

            $resumed = Workflow::make(workflowId: $token)
                ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

            $state = $this->resume(
                $resumed,
                $persistence,
                ['id' => 7],
            );

            $this->assertFalse($state->isInterrupted());
            $this->assertSame(['id' => 7], $state->get('received_payload'));
            $this->assertTrue($state->get('node_three_executed'));
        } finally {
            $this->removeDirectory($dir);
        }
    }

    public function test_wait_for_event_resume_without_payload(): void
    {
        $persistence = new InMemoryPersistence();
        $token = 'wfe-empty-payload';

        $workflow = Workflow::make(workflowId: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $this->execute($workflow, $persistence);

        $resumed = Workflow::make(workflowId: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        // Resume with an empty payload — the node receives an empty event body.
        $state = $this->resume(
            $resumed,
            $persistence,
            [],
        );

        $this->assertFalse($state->isInterrupted());
        $this->assertSame([], $state->get('received_payload'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function test_sleep_until_past_time_still_pauses(): void
    {
        // Every wait is persisted before continuation; a past deadline is then
        // resolved by the clock-aware, inputless resume.
        $workflow = Workflow::make(workflowId: 'sleep-past')
            ->addNodes([new NodeOne(), new SleepUntilNode(new DateTimeImmutable('-1 minute')), new NodeThree()]);

        $state = $this->execute($workflow);

        $this->assertTrue($state->isInterrupted());
        $this->assertInstanceOf(SleepUntilRequest::class, $state->getInterruptRequest());
        $this->assertFalse($state->has('node_three_executed'));

        $state = $workflow->run([]);
        $this->assertFalse($state->isInterrupted());
        $this->assertTrue($state->get('sleep_resumed'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function test_inputless_resume_keeps_future_sleep_suspended(): void
    {
        $workflow = Workflow::make(workflowId: 'sleep-future')
            ->addNodes([new NodeOne(), new SleepUntilNode(new DateTimeImmutable('+1 hour')), new NodeThree()]);

        $workflow->run();
        $state = $workflow->run([]);

        $this->assertTrue($state->isInterrupted());
        $this->assertFalse($state->get('sleep_resumed', false));
        $this->assertFalse($state->has('node_three_executed'));
    }

    public function test_inputless_resume_expires_due_event_wait(): void
    {
        $workflow = Workflow::make(workflowId: 'event-expired')
            ->addNodes([
                new NodeOne(),
                new WaitForEventWithTimeoutNode(new DateTimeImmutable('-1 minute')),
                new NodeThree(),
            ]);

        $workflow->run();
        $state = $workflow->run([]);

        $this->assertFalse($state->isInterrupted());
        $this->assertTrue($state->get('timed_out'));
        $this->assertFalse($state->has('received_payload'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function test_wait_for_event_request_carries_deadline(): void
    {
        // The deadline is an OUTBOUND term on the request (the node declares it;
        // the scheduler arms a timer from it). The timeout FACT is inbound
        // ($timedOut on resume), never stored on the request.
        $expiresAt = new DateTimeImmutable('2026-12-31T23:59:59+00:00');

        $request = new WaitForEventRequest('user.signup', $expiresAt);

        $this->assertSame($expiresAt, $request->getExpiresAt());

        // The deadline survives PHP serialization with the request.
        $restored = unserialize(serialize($request));
        $this->assertSame($expiresAt->getTimestamp(), $restored->getExpiresAt()->getTimestamp());

        // And the jsonSerialize/fromArray round-trip (JSON-based transport).
        $fromArray = WaitForEventRequest::fromArray($request->jsonSerialize());
        $this->assertSame($expiresAt->getTimestamp(), $fromArray->getExpiresAt()->getTimestamp());
    }

    public function test_approval_request_inherits_deadline(): void
    {
        // ApprovalRequest is a WaitForEventRequest specialization, so the deadline
        // capability is inherited — no special-casing needed for "auto-reject after T".
        $expiresAt = new DateTimeImmutable('+1 day');

        $request = new ApprovalRequest('Approve payment?', [], $expiresAt);

        $this->assertSame($expiresAt, $request->getExpiresAt());
        $this->assertSame(InterruptType::WaitForEvent, $request->type());
    }

    public function test_await_event_returns_null_on_timeout(): void
    {
        // The developer-facing timeout contract: when the deadline elapses the
        // scheduler resumes the wait with $timedOut, and awaitEvent() surfaces that
        // to the node as null. The node branches on null — it never inspects a flag.
        $persistence = new InMemoryPersistence();
        $token = 'wfe-timeout';

        // Run 1: suspends on a bounded wait. The expressed deadline is carried on
        // the interrupted request (outbound).
        $workflow = Workflow::make(workflowId: $token)
            ->addNodes([new NodeOne(), new WaitForEventWithTimeoutNode(), new NodeThree()]);
        $state = $this->execute($workflow, $persistence);

        $this->assertTrue($state->isInterrupted());
        $interrupt = $state->getInterruptRequest();
        $this->assertInstanceOf(WaitForEventRequest::class, $interrupt);
        $this->assertNotNull($interrupt->getExpiresAt());

        // Resume with $timedOut — exactly what the scheduler does when the
        // deadline fires.
        $resumed = Workflow::make(workflowId: $token)
            ->addNodes([new NodeOne(), new WaitForEventWithTimeoutNode(), new NodeThree()]);
        $state = $this->resume($resumed, $persistence, [], true);

        // The node's null branch ran: it saw no event, took the timeout path, and
        // the workflow continued to completion.
        $this->assertFalse($state->isInterrupted());
        $this->assertTrue($state->get('timed_out'));
        $this->assertFalse($state->has('received_payload'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }

        rmdir($dir);
    }
}
