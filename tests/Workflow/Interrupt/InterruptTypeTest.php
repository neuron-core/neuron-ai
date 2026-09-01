<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Interrupt;

use NeuronAI\Workflow\Interrupt\InterruptType;
use PHPUnit\Framework\TestCase;

class InterruptTypeTest extends TestCase
{
    public function test_cases_and_values(): void
    {
        $this->assertSame('wait_for_event', InterruptType::WaitForEvent->value);
        $this->assertSame('sleep_until', InterruptType::SleepUntil->value);
    }

    public function test_two_cases_only(): void
    {
        // The type vocabulary is closed: exactly two cases. Adding a type is a
        // framework concern (requires new scheduler logic).
        $this->assertCount(2, InterruptType::cases());
    }

    public function test_from_value(): void
    {
        $this->assertSame(InterruptType::WaitForEvent, InterruptType::from('wait_for_event'));
        $this->assertSame(InterruptType::SleepUntil, InterruptType::from('sleep_until'));
    }
}
