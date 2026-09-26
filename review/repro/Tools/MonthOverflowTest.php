<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\AddTimeTool;
use NeuronAI\Tools\Toolkits\Calendar\SubtractTimeTool;
use PHPUnit\Framework\TestCase;

class MonthOverflowTest extends TestCase
{
    public function test_adding_a_month_to_the_end_of_january_lands_in_february(): void
    {
        $this->assertSame('2023-02-28 00:00:00', (new AddTimeTool())('2023-01-31', 1, 'months'));
        $this->assertSame('2024-02-29 00:00:00', (new AddTimeTool())('2024-01-31', 1, 'months'));
    }

    public function test_subtracting_a_month_from_the_end_of_march_lands_in_february(): void
    {
        $this->assertSame('2023-02-28 00:00:00', (new SubtractTimeTool())('2023-03-31', 1, 'months'));
    }

    public function test_month_arithmetic_keeps_the_time_of_day_and_non_overflowing_days(): void
    {
        $this->assertSame('2023-04-30 13:45:10', (new AddTimeTool())('2023-03-31 13:45:10', 1, 'months'));
        $this->assertSame('2023-02-15 00:00:00', (new AddTimeTool())('2023-01-15', 1, 'months'));
        $this->assertSame('2024-01-31 00:00:00', (new AddTimeTool())('2023-12-31', 1, 'months'));
        $this->assertSame('2022-12-31 00:00:00', (new SubtractTimeTool())('2023-01-31', 1, 'months'));
    }
}
