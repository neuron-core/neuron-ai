<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Interrupt;

use DateTimeImmutable;
use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\TestCase;

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
}
