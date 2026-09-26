<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\AddTimeTool;
use NeuronAI\Tools\Toolkits\Calendar\SubtractTimeTool;
use PHPUnit\Framework\TestCase;

class FractionalAmountTest extends TestCase
{
    public function test_fractional_minutes_are_not_truncated_by_float_error(): void
    {
        $this->assertSame('2023-01-01 00:02:18', (new AddTimeTool())('2023-01-01 00:00:00', 2.3, 'minutes'));
        $this->assertSame('2022-12-31 23:57:42', (new SubtractTimeTool())('2023-01-01 00:00:00', 2.3, 'minutes'));
    }

    public function test_fractional_hours_are_not_truncated_by_float_error(): void
    {
        $this->assertSame('2023-01-01 01:09:00', (new AddTimeTool())('2023-01-01 00:00:00', 1.15, 'hours'));
    }

    public function test_fractional_weeks_are_not_silently_dropped(): void
    {
        $this->assertSame('2023-01-11 12:00:00', (new AddTimeTool())('2023-01-01 00:00:00', 1.5, 'weeks'));
        $this->assertSame('2022-12-21 12:00:00', (new SubtractTimeTool())('2023-01-01 00:00:00', 1.5, 'weeks'));
    }
}
