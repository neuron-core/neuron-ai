<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use DateTimeImmutable;
use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\TestCase;

class WaitForEventRequestTest extends TestCase
{
    public function testTypeIsWaitForEvent(): void
    {
        $this->assertSame(InterruptType::WaitForEvent, (new WaitForEventRequest('user.signup'))->type());
    }

    public function testGettersWithoutDeadline(): void
    {
        $request = new WaitForEventRequest('user.signup');
        $this->assertSame('user.signup', $request->getEventName());
        $this->assertNull($request->getExpiresAt());
    }

    public function testGettersWithDeadline(): void
    {
        $expiresAt = new DateTimeImmutable('2026-12-31T23:59:59+00:00');
        $request = new WaitForEventRequest('user.signup', $expiresAt);
        $this->assertSame('user.signup', $request->getEventName());
        $this->assertSame($expiresAt, $request->getExpiresAt());
    }

    public function testJsonRoundTripWithoutDeadline(): void
    {
        $original = new WaitForEventRequest('user.signup');
        $restored = WaitForEventRequest::fromArray($original->jsonSerialize());

        $this->assertSame($original->getEventName(), $restored->getEventName());
        $this->assertNull($restored->getExpiresAt());
        $this->assertSame($original->type(), $restored->type());
    }

    public function testJsonRoundTripWithDeadline(): void
    {
        $original = new WaitForEventRequest('order.paid', new DateTimeImmutable('2026-12-31T23:59:59+00:00'));
        $restored = WaitForEventRequest::fromArray($original->jsonSerialize());

        $this->assertSame($original->getEventName(), $restored->getEventName());
        $this->assertSame($original->getExpiresAt()->getTimestamp(), $restored->getExpiresAt()->getTimestamp());
    }
}
