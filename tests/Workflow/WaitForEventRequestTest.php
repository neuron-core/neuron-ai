<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\TestCase;

class WaitForEventRequestTest extends TestCase
{
    public function testTypeIsWaitForEvent(): void
    {
        $this->assertSame(InterruptType::WaitForEvent, (new WaitForEventRequest('user.signup'))->type());
    }

    public function testGettersWithoutPayload(): void
    {
        $request = new WaitForEventRequest('user.signup');
        $this->assertSame('user.signup', $request->getEventName());
        $this->assertNull($request->getPayload());
    }

    public function testGettersWithPayload(): void
    {
        $request = new WaitForEventRequest('user.signup', ['id' => 7]);
        $this->assertSame('user.signup', $request->getEventName());
        $this->assertSame(['id' => 7], $request->getPayload());
    }

    public function testJsonRoundTripWithoutPayload(): void
    {
        $original = new WaitForEventRequest('user.signup');
        $restored = WaitForEventRequest::fromArray($original->jsonSerialize());

        $this->assertSame($original->getEventName(), $restored->getEventName());
        $this->assertNull($restored->getPayload());
        $this->assertSame($original->type(), $restored->type());
    }

    public function testJsonRoundTripWithPayload(): void
    {
        $original = new WaitForEventRequest('order.paid', ['orderId' => 42, 'amount' => 99.5]);
        $restored = WaitForEventRequest::fromArray($original->jsonSerialize());

        $this->assertSame($original->getEventName(), $restored->getEventName());
        $this->assertSame($original->getPayload(), $restored->getPayload());
    }
}
