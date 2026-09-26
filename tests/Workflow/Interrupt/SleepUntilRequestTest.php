<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Interrupt;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\TestCase;

use function time;

use const DATE_ATOM;

class SleepUntilRequestTest extends TestCase
{
    public function test_type_is_sleep_until(): void
    {
        $this->assertSame(InterruptType::SleepUntil, (new SleepUntilRequest(new DateTimeImmutable()))->type());
    }

    public function test_getters(): void
    {
        $wakeAt = new DateTimeImmutable('2026-12-31 23:59:59', new DateTimeZone('UTC'));
        $request = new SleepUntilRequest($wakeAt);

        $this->assertEquals($wakeAt, $request->getWakeAt());
        $this->assertSame('Sleeping until 2026-12-31T23:59:59+00:00', $request->getMessage());
    }

    public function test_json_round_trip_preserves_timestamp(): void
    {
        $wakeAt = new DateTimeImmutable('2026-07-20T15:30:00+00:00');
        $original = new SleepUntilRequest($wakeAt);
        $restored = SleepUntilRequest::fromArray($original->jsonSerialize());

        $this->assertEquals($original->getWakeAt(), $restored->getWakeAt());
        $this->assertSame($original->type(), $restored->type());
    }

    public function test_bound_request_owns_the_complete_portable_envelope(): void
    {
        $request = (new SleepUntilRequest(new DateTimeImmutable('2026-07-20T15:30:00+02:00')))->withId(9);

        $this->assertSame([
            'interruptId' => 9,
            'type' => 'sleep_until',
            'wakeAt' => '2026-07-20T15:30:00+02:00',
        ], $request->jsonSerialize());

        $restored = SleepUntilRequest::fromArray($request->jsonSerialize());
        $this->assertSame(9, $restored->getId());
        $this->assertSame($request->jsonSerialize(), $restored->jsonSerialize());
    }

    public function test_a_due_timer_resumes_the_sleep(): void
    {
        $request = (new SleepUntilRequest(new DateTimeImmutable('@' . time())))->withId(1);

        $request->validate(ResumeInput::timer($request));

        $this->addToAssertionCount(1);
    }

    public function test_a_timer_before_the_wake_time_is_refused(): void
    {
        $wakeAt = new DateTimeImmutable('@' . (time() + 60));
        $request = (new SleepUntilRequest($wakeAt))->withId(2);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Timer input for interrupt 2 arrived before its wake time ' . $wakeAt->format(DATE_ATOM) . '.');

        $request->validate(ResumeInput::timer($request));
    }

    public function test_a_timer_one_second_before_the_wake_time_is_refused(): void
    {
        // Retried until the clock stays on one second, so the boundary is exact.
        do {
            $now = time();
            $request = (new SleepUntilRequest(new DateTimeImmutable('@' . ($now + 1))))->withId(2);
            try {
                $request->validate(ResumeInput::timer($request));
                $refused = false;
            } catch (WorkflowException) {
                $refused = true;
            }
        } while (time() !== $now);

        $this->assertTrue($refused);
    }

    public function test_an_event_cannot_end_a_sleep(): void
    {
        $request = (new SleepUntilRequest(new DateTimeImmutable('@1')))->withId(3);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Resume input 'event' is incompatible with interrupt 3 of type 'sleep_until'.");

        $request->validate(ResumeInput::event($request, ['woken' => true]));
    }

    public function test_an_expiry_cannot_end_a_sleep(): void
    {
        $request = (new SleepUntilRequest(new DateTimeImmutable('@1')))->withId(4);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Resume input 'expired' is incompatible with interrupt 4 of type 'sleep_until'.");

        $request->validate(ResumeInput::expired((new WaitForEventRequest('late', new DateTimeImmutable('@1')))->withId(4)));
    }

    public function test_from_array_rejects_a_non_positive_interrupt_id(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('An interrupt ID must be a positive integer.');

        SleepUntilRequest::fromArray(['interruptId' => 0, 'wakeAt' => '2026-07-20T15:30:00+00:00']);
    }

    public function test_from_array_rejects_an_unparseable_wake_time(): void
    {
        $this->expectException(Exception::class);

        SleepUntilRequest::fromArray(['wakeAt' => 'not a date']);
    }
}
