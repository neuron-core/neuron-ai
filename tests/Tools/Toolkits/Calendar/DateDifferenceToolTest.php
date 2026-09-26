<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\DateDifferenceTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;

class DateDifferenceToolTest extends TestCase
{
    protected DateDifferenceTool $tool;

    protected function setUp(): void
    {
        $this->tool = new DateDifferenceTool();
    }

    public function test_date_difference_in_days(): void
    {
        $result = ($this->tool)('2023-01-01', '2023-01-10');

        $this->assertSame('9', $result);
    }

    public function test_date_difference_in_seconds(): void
    {
        $result = ($this->tool)('2023-01-01 10:00:00', '2023-01-01 10:01:30', 'seconds');

        $this->assertSame('90', $result);
    }

    public function test_date_difference_in_minutes(): void
    {
        $result = ($this->tool)('2023-01-01 10:00:00', '2023-01-01 11:30:00', 'minutes');

        $this->assertSame('90', $result);
    }

    public function test_date_difference_in_hours(): void
    {
        $result = ($this->tool)('2023-01-01 10:00:00', '2023-01-02 14:00:00', 'hours');

        $this->assertSame('28', $result);
    }

    public function test_date_difference_in_weeks(): void
    {
        $result = ($this->tool)('2023-01-01', '2023-01-15', 'weeks');

        $this->assertSame('2', $result);
    }

    public function test_date_difference_in_months_includes_whole_years(): void
    {
        $result = ($this->tool)('2022-01-01', '2023-07-01', 'months');

        $this->assertSame('18', $result);
    }

    public function test_date_difference_in_years(): void
    {
        $result = ($this->tool)('2020-01-01', '2023-01-01', 'years');

        $this->assertSame('3', $result);
    }

    public function test_date_difference_all(): void
    {
        $result = ($this->tool)('2022-01-15 10:30:45', '2023-03-20 14:45:30', 'all');

        $this->assertSame([
            'years' => 1,
            'months' => 2,
            'days' => 5,
            'hours' => 4,
            'minutes' => 14,
            'seconds' => 45,
            'total_days' => 429,
            'total_seconds' => 37080885,
        ], json_decode($result, true));
    }

    public function test_the_breakdown_is_the_same_whatever_the_order_of_the_dates(): void
    {
        $this->assertSame(
            ($this->tool)('2022-01-15 10:30:45', '2023-03-20 14:45:30', 'all'),
            ($this->tool)('2023-03-20 14:45:30', '2022-01-15 10:30:45', 'all')
        );
    }

    public function test_partial_units_are_rounded_to_two_decimals(): void
    {
        $this->assertSame('1.43', ($this->tool)('2023-01-01', '2023-01-11', 'weeks'));
        $this->assertSame('0.83', ($this->tool)('2023-01-01 10:00:00', '2023-01-01 10:00:50', 'minutes'));
        $this->assertSame('1.5', ($this->tool)('2023-01-01 10:00:00', '2023-01-01 11:30:00', 'hours'));
        $this->assertSame('1.02', ($this->tool)('2023-01-01 10:00:00', '2023-01-01 11:01:00', 'hours'));
    }

    public function test_a_leap_year_spans_366_days(): void
    {
        $this->assertSame('366', ($this->tool)('2024-01-01', '2025-01-01'));
        $this->assertSame('365', ($this->tool)('2023-01-01', '2024-01-01'));
    }

    public function test_the_spring_forward_day_lasts_23_hours(): void
    {
        $this->assertSame('23', ($this->tool)('2024-03-10 00:00:00', '2024-03-11 00:00:00', 'hours', 'America/New_York'));
        $this->assertSame('1', ($this->tool)('2024-03-10 00:00:00', '2024-03-11 00:00:00', 'days', 'America/New_York'));
    }

    public function test_the_fall_back_day_lasts_25_hours(): void
    {
        $this->assertSame('25', ($this->tool)('2024-11-03 00:00:00', '2024-11-04 00:00:00', 'hours', 'America/New_York'));
    }

    public function test_an_unknown_unit_falls_back_to_days(): void
    {
        $this->assertSame('9', ($this->tool)('2023-01-01', '2023-01-10', 'fortnights'));
    }

    public function test_date_difference_with_timestamps(): void
    {
        $start = '1672531200'; // 2023-01-01 00:00:00 UTC
        $end = '1672617600';   // 2023-01-02 00:00:00 UTC

        $result = ($this->tool)($start, $end);

        $this->assertSame('1', $result);
    }

    public function test_date_difference_with_timezone(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', '2023-01-02 12:00:00', 'hours', 'America/New_York');

        $this->assertSame('24', $result);
    }

    public function test_date_difference_reversed_dates(): void
    {
        $result = ($this->tool)('2023-01-10', '2023-01-01');

        $this->assertSame('9', $result); // Should return absolute difference
    }

    public function test_date_difference_negative_result(): void
    {
        $result1 = ($this->tool)('2023-01-01', '2023-01-10', 'seconds');
        $result2 = ($this->tool)('2023-01-10', '2023-01-01', 'seconds');

        // Both should return the same absolute value
        $this->assertSame($result1, $result2);
    }

    public function test_invalid_start_date(): void
    {
        $result = ($this->tool)('invalid-date', '2023-01-01');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('invalid-date', $result);
    }

    public function test_invalid_end_date(): void
    {
        $result = ($this->tool)('2023-01-01', 'invalid-date');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('invalid-date', $result);
    }

    public function test_invalid_timezone(): void
    {
        $result = ($this->tool)('2023-01-01', '2023-01-02', null, 'Invalid/Timezone');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Invalid/Timezone', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('date_difference', $this->tool->getName());
        $this->assertSame('Calculate the difference between two dates in various units', $this->tool->getDescription());

        $this->assertSame(
            ['start_date', 'end_date', 'unit', 'timezone'],
            array_map(fn (ToolPropertyInterface $property): string => $property->getName(), $this->tool->getProperties())
        );
        $this->assertSame(['start_date', 'end_date'], $this->tool->getRequiredProperties());
    }
}
