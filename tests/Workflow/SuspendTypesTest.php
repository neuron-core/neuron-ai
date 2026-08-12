<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\ObjectCarryingInterruptNode;
use NeuronAI\Tests\Workflow\Stubs\ObjectCarryingRequest;
use NeuronAI\Tests\Workflow\Stubs\SleepUntilNode;
use NeuronAI\Tests\Workflow\Stubs\WaitForEventNode;
use NeuronAI\Tests\Workflow\Stubs\WaitForEventWithTimeoutNode;
use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function serialize;
use function unserialize;

use const DIRECTORY_SEPARATOR;

class SuspendTypesTest extends TestCase
{
    use ExecutorTestHelpers;

    public function testWaitForEventPausesAndResumes(): void
    {
        $persistence = new InMemoryPersistence();
        $token = 'wfe-basic';

        $workflow = Workflow::make(address: $token)
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
        $resumed = Workflow::make(address: $token)
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

    public function testSleepUntilPausesAndResumes(): void
    {
        $persistence = new InMemoryPersistence();
        $token = 'sleep-basic';

        $workflow = Workflow::make(address: $token)
            ->addNodes([new NodeOne(), new SleepUntilNode(), new NodeThree()]);

        $state = $this->execute($workflow, $persistence);

        $this->assertTrue($state->isInterrupted());
        $interrupt = $state->getInterruptRequest();
        $this->assertInstanceOf(SleepUntilRequest::class, $interrupt);
        $this->assertSame(InterruptType::SleepUntil, $interrupt->type());
        $this->assertFalse($state->has('node_three_executed'));

        // Resume carries no payload — the wakeup itself is the signal (empty payload).
        $resumed = Workflow::make(address: $token)
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

    public function testWaitForEventSurvivesFilePersistenceSerialization(): void
    {
        // FilePersistence forces real PHP serialize/unserialize of the interrupted
        // StepResult across the pause/resume. Under fire-and-forget only the
        // interrupted flag is persisted (the request is rebuilt by re-running the
        // node), so serialization is trivially safe.
        $dir = sys_get_temp_dir() . '/neuron_test_wfe_serial';
        $persistence = new FilePersistence($dir);
        $token = 'wfe-serial';

        try {
            $workflow = Workflow::make(address: $token)
                ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

            $request = $this->execute($workflow, $persistence)->getInterruptRequest();
            $this->assertInstanceOf(WaitForEventRequest::class, $request);

            $resumed = Workflow::make(address: $token)
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

    public function testWaitForEventResumeWithoutPayload(): void
    {
        $persistence = new InMemoryPersistence();
        $token = 'wfe-empty-payload';

        $workflow = Workflow::make(address: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $this->execute($workflow, $persistence);

        $resumed = Workflow::make(address: $token)
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

    public function testSleepUntilPastTimeStillPauses(): void
    {
        // The engine does NOT enforce timeliness — a past wakeAt still suspends.
        // Whether to fire is exclusively the scheduler's responsibility.
        $workflow = Workflow::make(address: 'sleep-past')
            ->addNodes([new NodeOne(), new SleepUntilNode(new DateTimeImmutable('-1 minute')), new NodeThree()]);

        $state = $this->execute($workflow);

        $this->assertTrue($state->isInterrupted());
        $this->assertInstanceOf(SleepUntilRequest::class, $state->getInterruptRequest());
        $this->assertFalse($state->has('node_three_executed'));
    }

    public function testWaitForEventRequestCarriesDeadline(): void
    {
        // The deadline is an OUTBOUND term on the request (the node declares it;
        // the scheduler arms a timer from it). The timeout FACT is inbound
        // ($timedOut on resume), never stored on the request.
        $expiresAt = new DateTimeImmutable('2026-12-31T23:59:59+00:00');

        $request = new WaitForEventRequest('user.signup', $expiresAt);

        $this->assertSame($expiresAt, $request->getExpiresAt());

        // The deadline survives PHP serialize (FilePersistence) — but only as an
        // outbound term; the request itself is not persisted across a suspend.
        $restored = unserialize(serialize($request));
        $this->assertSame($expiresAt->getTimestamp(), $restored->getExpiresAt()->getTimestamp());

        // And the jsonSerialize/fromArray round-trip (JSON-based transport).
        $fromArray = WaitForEventRequest::fromArray($request->jsonSerialize());
        $this->assertSame($expiresAt->getTimestamp(), $fromArray->getExpiresAt()->getTimestamp());
    }

    public function testApprovalRequestInheritsDeadline(): void
    {
        // ApprovalRequest is a WaitForEventRequest specialization, so the deadline
        // capability is inherited — no special-casing needed for "auto-reject after T".
        $expiresAt = new DateTimeImmutable('+1 day');

        $request = new ApprovalRequest('Approve payment?', [], $expiresAt);

        $this->assertSame($expiresAt, $request->getExpiresAt());
        $this->assertSame(InterruptType::WaitForEvent, $request->type());
    }

    public function testAwaitEventReturnsNullOnTimeout(): void
    {
        // The developer-facing timeout contract: when the deadline elapses the
        // scheduler resumes the wait with $timedOut, and awaitEvent() surfaces that
        // to the node as null. The node branches on null — it never inspects a flag.
        $persistence = new InMemoryPersistence();
        $token = 'wfe-timeout';

        // Run 1: suspends on a bounded wait. The expressed deadline is carried on
        // the interrupted request (outbound).
        $workflow = Workflow::make(address: $token)
            ->addNodes([new NodeOne(), new WaitForEventWithTimeoutNode(), new NodeThree()]);
        $state = $this->execute($workflow, $persistence);

        $this->assertTrue($state->isInterrupted());
        $interrupt = $state->getInterruptRequest();
        $this->assertInstanceOf(WaitForEventRequest::class, $interrupt);
        $this->assertNotNull($interrupt->getExpiresAt());

        // Resume with $timedOut — exactly what the scheduler does when the
        // deadline fires.
        $resumed = Workflow::make(address: $token)
            ->addNodes([new NodeOne(), new WaitForEventWithTimeoutNode(), new NodeThree()]);
        $state = $this->resume($resumed, $persistence, [], true);

        // The node's null branch ran: it saw no event, took the timeout path, and
        // the workflow continued to completion.
        $this->assertFalse($state->isInterrupted());
        $this->assertTrue($state->get('timed_out'));
        $this->assertFalse($state->has('received_payload'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function testRequestCarryingNonSerializableObjectIsNotSerialized(): void
    {
        // Fire-and-forget: the InterruptRequest is never persisted, so a developer can
        // stuff a non-serializable object (here a closure) into a custom request without
        // breaking FilePersistence's serialize across the pause. Under the old design —
        // which persisted the request into the StepResult — this would have thrown.
        $dir = sys_get_temp_dir() . '/neuron_test_object_carrying';
        $persistence = new FilePersistence($dir);
        $token = 'object-carrying';

        try {
            $workflow = Workflow::make(address: $token)
                ->addNodes([new NodeOne(), new ObjectCarryingInterruptNode(), new NodeThree()]);
            $state = $this->execute($workflow, $persistence);

            $this->assertTrue($state->isInterrupted());
            $this->assertInstanceOf(ObjectCarryingRequest::class, $state->getInterruptRequest());

            $resumed = Workflow::make(address: $token)
                ->addNodes([new NodeOne(), new ObjectCarryingInterruptNode(), new NodeThree()]);
            $state = $this->resume($resumed, $persistence, []);

            $this->assertFalse($state->isInterrupted());
            $this->assertTrue($state->get('resumed_with_object'));
            $this->assertTrue($state->get('node_three_executed'));
        } finally {
            $this->removeDirectory($dir);
        }
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
