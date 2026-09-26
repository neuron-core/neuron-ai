<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Interrupt;

use DateTimeImmutable;
use Exception;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\TestCase;

use function time;

use const DATE_ATOM;

class WaitForEventRequestTest extends TestCase
{
    public function test_type_is_wait_for_event(): void
    {
        $this->assertSame(InterruptType::WaitForEvent, (new WaitForEventRequest('user.signup'))->type());
    }

    public function test_getters_without_deadline(): void
    {
        $request = new WaitForEventRequest('user.signup');
        $this->assertSame('user.signup', $request->getEventName());
        $this->assertNull($request->getExpiresAt());
    }

    public function test_getters_with_deadline(): void
    {
        $expiresAt = new DateTimeImmutable('2026-12-31T23:59:59+00:00');
        $request = new WaitForEventRequest('user.signup', $expiresAt);
        $this->assertSame('user.signup', $request->getEventName());
        $this->assertSame($expiresAt, $request->getExpiresAt());
    }

    public function test_json_round_trip_without_deadline(): void
    {
        $original = new WaitForEventRequest('user.signup');
        $restored = WaitForEventRequest::fromArray($original->jsonSerialize());

        $this->assertSame($original->getEventName(), $restored->getEventName());
        $this->assertNull($restored->getExpiresAt());
        $this->assertSame($original->type(), $restored->type());
    }

    public function test_json_round_trip_with_deadline(): void
    {
        $original = new WaitForEventRequest('order.paid', new DateTimeImmutable('2026-12-31T23:59:59+00:00'));
        $restored = WaitForEventRequest::fromArray($original->jsonSerialize());

        $this->assertSame($original->getEventName(), $restored->getEventName());
        $this->assertSame($original->getExpiresAt()->getTimestamp(), $restored->getExpiresAt()->getTimestamp());
    }

    public function test_bound_request_owns_the_complete_portable_envelope(): void
    {
        $request = (new WaitForEventRequest('order.paid'))->withId(7);

        $this->assertSame(7, $request->getId());
        $this->assertSame([
            'interruptId' => 7,
            'type' => 'wait_for_event',
            'eventName' => 'order.paid',
            'expiresAt' => null,
        ], $request->jsonSerialize());

        $restored = WaitForEventRequest::fromArray($request->jsonSerialize());
        $this->assertSame(7, $restored->getId());
    }

    public function test_message_names_the_event_and_its_deadline(): void
    {
        $this->assertSame("Waiting for event 'order.paid'", (new WaitForEventRequest('order.paid'))->getMessage());
        $this->assertSame(
            "Waiting for event 'order.paid' (expires at 2026-12-31T23:59:59+02:00)",
            (new WaitForEventRequest('order.paid', new DateTimeImmutable('2026-12-31T23:59:59+02:00')))->getMessage(),
        );
    }

    public function test_envelope_keeps_the_deadline_offset(): void
    {
        $request = new WaitForEventRequest('order.paid', new DateTimeImmutable('2026-12-31T23:59:59-05:00'));

        $this->assertSame('2026-12-31T23:59:59-05:00', $request->jsonSerialize()['expiresAt']);
    }

    public function test_an_event_answers_the_wait_with_or_without_a_deadline(): void
    {
        $open = (new WaitForEventRequest('order.paid'))->withId(1);
        $bounded = (new WaitForEventRequest('order.paid', new DateTimeImmutable('@' . (time() + 3600))))->withId(2);

        $open->validate(ResumeInput::event($open, ['paid' => true]));
        $bounded->validate(ResumeInput::event($bounded, []));

        $this->addToAssertionCount(2);
    }

    public function test_a_due_deadline_accepts_its_expiry(): void
    {
        $request = (new WaitForEventRequest('order.paid', new DateTimeImmutable('@' . time())))->withId(1);

        $request->validate(ResumeInput::expired($request));

        $this->addToAssertionCount(1);
    }

    public function test_an_expiry_before_the_deadline_is_refused(): void
    {
        $deadline = new DateTimeImmutable('@' . (time() + 60));
        $request = (new WaitForEventRequest('order.paid', $deadline))->withId(4);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Expiry input for interrupt 4 arrived before its deadline ' . $deadline->format(DATE_ATOM) . '.');

        $request->validate(ResumeInput::expired($request));
    }

    public function test_an_expiry_one_second_before_the_deadline_is_refused(): void
    {
        // Retried until the clock stays on one second, so the boundary is exact.
        do {
            $now = time();
            $request = (new WaitForEventRequest('order.paid', new DateTimeImmutable('@' . ($now + 1))))->withId(4);
            try {
                $request->validate(ResumeInput::expired($request));
                $refused = false;
            } catch (WorkflowException) {
                $refused = true;
            }
        } while (time() !== $now);

        $this->assertTrue($refused);
    }

    public function test_a_wait_without_deadline_cannot_expire(): void
    {
        $request = (new WaitForEventRequest('order.paid'))->withId(5);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Resume input 'expired' is incompatible with interrupt 5 of type 'wait_for_event'.");

        $request->validate(ResumeInput::expired($request));
    }

    public function test_a_timer_cannot_answer_an_event_wait(): void
    {
        $request = (new WaitForEventRequest('order.paid', new DateTimeImmutable('@1')))->withId(6);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Resume input 'timer' is incompatible with interrupt 6 of type 'wait_for_event'.");

        $request->validate(ResumeInput::timer($request));
    }

    public function test_from_array_rejects_a_non_positive_interrupt_id(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('An interrupt ID must be a positive integer.');

        WaitForEventRequest::fromArray(['interruptId' => -3, 'eventName' => 'order.paid']);
    }

    public function test_from_array_rejects_an_unparseable_deadline(): void
    {
        $this->expectException(Exception::class);

        WaitForEventRequest::fromArray(['eventName' => 'order.paid', 'expiresAt' => 'not a date']);
    }
}
