<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use PHPUnit\Framework\TestCase;

class SleepUntilRequestTest extends TestCase
{
    public function testTypeIsSleepUntil(): void
    {
        $this->assertSame(InterruptType::SleepUntil, (new SleepUntilRequest(new DateTimeImmutable()))->type());
    }

    public function testGetters(): void
    {
        $wakeAt = new DateTimeImmutable('2026-12-31 23:59:59', new DateTimeZone('UTC'));
        $request = new SleepUntilRequest($wakeAt);

        $this->assertEquals($wakeAt, $request->getWakeAt());
    }

    public function testJsonRoundTripPreservesTimestamp(): void
    {
        $wakeAt = new DateTimeImmutable('2026-07-20T15:30:00+00:00');
        $original = new SleepUntilRequest($wakeAt);
        $restored = SleepUntilRequest::fromArray($original->jsonSerialize());

        $this->assertEquals($original->getWakeAt(), $restored->getWakeAt());
        $this->assertSame($original->type(), $restored->type());
    }
}
