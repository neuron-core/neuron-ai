<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Interrupt;

use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\TestCase;

use function array_keys;

class InterruptEnvelopeIdentityTest extends TestCase
{
    public function test_subclass_metadata_cannot_forge_the_envelope_identity(): void
    {
        $request = (new class ('order.paid') extends WaitForEventRequest {
            protected function metadata(): array
            {
                return ['interruptId' => 999, 'type' => 'sleep_until'];
            }
        })->withId(3);

        $envelope = $request->jsonSerialize();

        $this->assertSame(3, $envelope['interruptId']);
        $this->assertSame('wait_for_event', $envelope['type']);
        $this->assertSame('order.paid', $envelope['eventName']);
        $this->assertSame(['interruptId', 'type', 'eventName', 'expiresAt'], array_keys($envelope));
    }
}
