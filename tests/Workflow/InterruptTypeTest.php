<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\Interrupt\InterruptType;
use PHPUnit\Framework\TestCase;

class InterruptTypeTest extends TestCase
{
    public function testCasesAndValues(): void
    {
        $this->assertSame('wait_for_event', InterruptType::WaitForEvent->value);
        $this->assertSame('sleep_until', InterruptType::SleepUntil->value);
    }

    public function testTwoCasesOnly(): void
    {
        // The type vocabulary is closed: exactly two cases. Adding a type is a
        // framework concern (requires new scheduler logic).
        $this->assertCount(2, InterruptType::cases());
    }

    public function testFromValue(): void
    {
        $this->assertSame(InterruptType::WaitForEvent, InterruptType::from('wait_for_event'));
        $this->assertSame(InterruptType::SleepUntil, InterruptType::from('sleep_until'));
    }
}
