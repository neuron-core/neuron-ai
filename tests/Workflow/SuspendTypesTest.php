<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
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
use TypeError;

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
        $executor = $this->createExecutor($persistence);
        $token = 'wfe-basic';

        $workflow = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $state = $this->execute($workflow, $executor);

        // Paused on the wait-for-event; downstream node did not run.
        $this->assertTrue($state->isInterrupted());
        $interrupt = $state->getInterruptRequest();
        $this->assertInstanceOf(WaitForEventRequest::class, $interrupt);
        $this->assertSame(InterruptType::WaitForEvent, $interrupt->type());
        $this->assertSame('user.signup', $interrupt->getEventName());
        $this->assertFalse($state->has('node_three_executed'));

        // Resume on a fresh executor sharing the persistence, hydrating the payload.
        $resumed = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $state = $this->execute(
            $resumed,
            $this->createExecutor($persistence),
            new WaitForEventRequest('user.signup', payload: ['id' => 7]),
        );

        $this->assertFalse($state->isInterrupted());
        $this->assertSame(['id' => 7], $state->get('received_payload'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function testSleepUntilPausesAndResumes(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);
        $token = 'sleep-basic';

        $workflow = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new SleepUntilNode(), new NodeThree()]);

        $state = $this->execute($workflow, $executor);

        $this->assertTrue($state->isInterrupted());
        $interrupt = $state->getInterruptRequest();
        $this->assertInstanceOf(SleepUntilRequest::class, $interrupt);
        $this->assertSame(InterruptType::SleepUntil, $interrupt->type());
        $this->assertFalse($state->has('node_three_executed'));

        // Resume carries no payload — the wakeup itself is the signal.
        $resumed = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new SleepUntilNode(), new NodeThree()]);

        $state = $this->execute(
            $resumed,
            $this->createExecutor($persistence),
            new SleepUntilRequest(new DateTimeImmutable('+1 hour')),
        );

        $this->assertFalse($state->isInterrupted());
        $this->assertTrue($state->get('sleep_resumed'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function testWaitForEventSurvivesFilePersistenceSerialization(): void
    {
        // FilePersistence forces real PHP serialize/unserialize of the interrupted
        // StepResult (including the InterruptRequest) across the pause/resume.
        $dir = sys_get_temp_dir() . '/neuron_test_wfe_serial';
        $persistence = new FilePersistence($dir);
        $token = 'wfe-serial';

        try {
            $workflow = Workflow::make(resumeToken: $token)
                ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

            $request = $this->execute($workflow, $this->createExecutor($persistence))->getInterruptRequest();
            $this->assertInstanceOf(WaitForEventRequest::class, $request);

            $resumed = Workflow::make(resumeToken: $token)
                ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

            $state = $this->execute(
                $resumed,
                $this->createExecutor($persistence),
                new WaitForEventRequest('user.signup', payload: ['id' => 7]),
            );

            $this->assertFalse($state->isInterrupted());
            $this->assertSame(['id' => 7], $state->get('received_payload'));
            $this->assertTrue($state->get('node_three_executed'));
        } finally {
            $this->removeDirectory($dir);
        }
    }

    public function testResumeWithWrongRequestTypeIsRejectedAtVerbBoundary(): void
    {
        // The engine is opaque to resume type — it does NOT reject a mismatched
        // resume. The verb layer does: awaitEvent()'s declared return type is
        // ?WaitForEventRequest, so a cross-TYPE resume (a SleepUntilRequest) is
        // rejected with a TypeError at the verb boundary. (Cross-class same-type
        // — e.g. an ApprovalRequest, which IS-A WaitForEventRequest — is not
        // caught here; verifying the concrete payload is the node's job.)
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);
        $token = 'wfe-wrong-type';

        $workflow = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $this->execute($workflow, $executor);

        $resumed = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $this->expectException(TypeError::class);
        $this->execute(
            $resumed,
            $this->createExecutor($persistence),
            new SleepUntilRequest(new DateTimeImmutable('+1 hour')),
        );
    }

    public function testWaitForEventResumeWithoutPayload(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);
        $token = 'wfe-null-payload';

        $workflow = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        $this->execute($workflow, $executor);

        $resumed = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);

        // Resume without a payload — the node tolerates null and continues.
        $state = $this->execute(
            $resumed,
            $this->createExecutor($persistence),
            new WaitForEventRequest('user.signup'),
        );

        $this->assertFalse($state->isInterrupted());
        $this->assertNull($state->get('received_payload'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function testSleepUntilPastTimeStillPauses(): void
    {
        // The engine does NOT enforce timeliness — a past wakeAt still suspends.
        // Whether to fire is exclusively the scheduler's responsibility.
        $workflow = Workflow::make(resumeToken: 'sleep-past')
            ->addNodes([new NodeOne(), new SleepUntilNode(new DateTimeImmutable('-1 minute')), new NodeThree()]);

        $state = $this->execute($workflow, $this->createExecutor());

        $this->assertTrue($state->isInterrupted());
        $this->assertInstanceOf(SleepUntilRequest::class, $state->getInterruptRequest());
        $this->assertFalse($state->has('node_three_executed'));
    }

    public function testWaitForEventRequestCarriesExpiration(): void
    {
        // Expiration is a scheduler-driven feature: the deadline lives on the
        // request (persisted) while the scheduler owns the timer. The node branches
        // on the explicit isExpired() flag — never a clock comparison.
        $expiresAt = new DateTimeImmutable('2026-12-31T23:59:59+00:00');

        $request = new WaitForEventRequest('user.signup', null, $expiresAt);

        $this->assertSame($expiresAt, $request->getExpiresAt());
        $this->assertFalse($request->isExpired());

        $request->markExpired();
        $this->assertTrue($request->isExpired());

        // The deadline and flag survive PHP serialize (what FilePersistence uses).
        $restored = unserialize(serialize($request));
        $this->assertSame($expiresAt->getTimestamp(), $restored->getExpiresAt()->getTimestamp());
        $this->assertTrue($restored->isExpired());

        // And the jsonSerialize/fromArray round-trip (JSON-based transport).
        $fromArray = WaitForEventRequest::fromArray($request->jsonSerialize());
        $this->assertSame($expiresAt->getTimestamp(), $fromArray->getExpiresAt()->getTimestamp());
        $this->assertTrue($fromArray->isExpired());
    }

    public function testApprovalRequestInheritsExpiration(): void
    {
        // ApprovalRequest is a WaitForEventRequest specialization, so the deadline
        // capability is inherited — no special-casing needed for "auto-reject after T".
        $expiresAt = new DateTimeImmutable('+1 day');

        $request = new ApprovalRequest('Approve payment?', [], $expiresAt);

        $this->assertSame($expiresAt, $request->getExpiresAt());
        $this->assertFalse($request->isExpired());

        $request->markExpired();
        $this->assertTrue($request->isExpired());
        $this->assertSame(InterruptType::WaitForEvent, $request->type());
    }

    public function testAwaitEventReturnsNullOnTimeout(): void
    {
        // The developer-facing timeout contract: when the deadline elapses the
        // scheduler resumes the wait with it marked expired, and awaitEvent()
        // surfaces that to the node as null. The node branches on null (Inngest
        // style) — it never inspects isExpired(), which stays internal plumbing.
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);
        $token = 'wfe-timeout';

        // Run 1: suspends on a bounded wait. The expressed deadline is carried on
        // the interrupted request.
        $workflow = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new WaitForEventWithTimeoutNode(), new NodeThree()]);
        $state = $this->execute($workflow, $executor);

        $this->assertTrue($state->isInterrupted());
        $interrupt = $state->getInterruptRequest();
        $this->assertInstanceOf(WaitForEventRequest::class, $interrupt);
        $this->assertNotNull($interrupt->getExpiresAt());

        // Resume with the wait marked expired — exactly what the scheduler does
        // when the deadline fires.
        $expired = new WaitForEventRequest('user.signup');
        $expired->markExpired();

        $resumed = Workflow::make(resumeToken: $token)
            ->addNodes([new NodeOne(), new WaitForEventWithTimeoutNode(), new NodeThree()]);
        $state = $this->execute($resumed, $executor, $expired);

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
