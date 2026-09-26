<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\AddTimeTool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;

class AddTimeToolTest extends TestCase
{
    protected AddTimeTool $tool;

    protected function setUp(): void
    {
        $this->tool = new AddTimeTool();
    }

    public function test_add_seconds(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 30, 'seconds');

        $this->assertSame('2023-01-01 12:00:30', $result);
    }

    public function test_add_minutes(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 45, 'minutes');

        $this->assertSame('2023-01-01 12:45:00', $result);
    }

    public function test_add_hours(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 6, 'hours');

        $this->assertSame('2023-01-01 18:00:00', $result);
    }

    public function test_add_days(): void
    {
        $result = ($this->tool)('2023-01-01', 5, 'days');

        $this->assertSame('2023-01-06 00:00:00', $result);
    }

    public function test_add_weeks(): void
    {
        $result = ($this->tool)('2023-01-01', 2, 'weeks');

        $this->assertSame('2023-01-15 00:00:00', $result);
    }

    public function test_add_months(): void
    {
        $result = ($this->tool)('2023-01-15', 3, 'months');

        $this->assertSame('2023-04-15 00:00:00', $result);
    }

    public function test_add_years(): void
    {
        $result = ($this->tool)('2020-02-29', 1, 'years'); // Leap year

        $this->assertSame('2021-03-01 00:00:00', $result); // PHP's behavior when leap day doesn't exist
    }

    public function test_add_with_timestamp(): void
    {
        $timestamp = '1672531200'; // 2023-01-01 00:00:00 UTC
        $result = ($this->tool)($timestamp, 1, 'days');

        $this->assertSame('2023-01-02 00:00:00', $result);
    }

    public function test_add_with_timezone(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 12, 'hours', 'America/New_York');

        $this->assertSame('2023-01-02 00:00:00', $result);
    }

    public function test_add_with_custom_format(): void
    {
        $result = ($this->tool)('2023-01-01', 1, 'days', null, 'Y/m/d');

        $this->assertSame('2023/01/02', $result);
    }

    public function test_add_float_amount(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 1.5, 'hours');

        $this->assertSame('2023-01-01 13:30:00', $result);
    }

    public function test_add_large_amount(): void
    {
        $result = ($this->tool)('2023-01-01', 365, 'days');

        $this->assertSame('2024-01-01 00:00:00', $result);
    }

    public function test_add_across_month_boundary(): void
    {
        $result = ($this->tool)('2023-01-28', 5, 'days');

        $this->assertSame('2023-02-02 00:00:00', $result);
    }

    public function test_add_across_year_boundary(): void
    {
        $result = ($this->tool)('2023-12-30', 3, 'days');

        $this->assertSame('2024-01-02 00:00:00', $result);
    }

    public function test_add_to_leap_year_february(): void
    {
        $result = ($this->tool)('2024-02-28', 1, 'days'); // 2024 is leap year

        $this->assertSame('2024-02-29 00:00:00', $result);
    }

    public function test_invalid_date(): void
    {
        $result = ($this->tool)('invalid-date', 1, 'days');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('invalid-date', $result);
    }

    public function test_invalid_unit(): void
    {
        $result = ($this->tool)('2023-01-01', 1, 'fortnights');

        $this->assertSame('Error: Unsupported unit: fortnights', $result);
    }

    public function test_a_negative_amount_is_rejected(): void
    {
        $result = ($this->tool)('2023-01-10', -1, 'days');

        $this->assertStringStartsWith('Error: ', $result);
    }

    public function test_a_zero_amount_leaves_the_date_unchanged(): void
    {
        $this->assertSame('2023-01-10 08:15:00', ($this->tool)('2023-01-10 08:15:00', 0, 'hours'));
    }

    /**
     * @return array<string, array{float, string, string}>
     */
    public static function fractionalAmounts(): array
    {
        return [
            'half a minute' => [0.5, 'minutes', '2023-01-01 12:00:30'],
            'a minute and a half' => [1.5, 'minutes', '2023-01-01 12:01:30'],
            'a quarter of an hour' => [0.25, 'hours', '2023-01-01 12:15:00'],
            'half a day' => [0.5, 'days', '2023-01-02 00:00:00'],
            'a day and a quarter' => [1.25, 'days', '2023-01-02 18:00:00'],
        ];
    }

    #[DataProvider('fractionalAmounts')]
    public function test_fractional_amounts_add_the_remainder_in_seconds(float $amount, string $unit, string $expected): void
    {
        $this->assertSame($expected, ($this->tool)('2023-01-01 12:00:00', $amount, $unit));
    }

    public function test_hours_are_elapsed_time_across_the_spring_forward_gap(): void
    {
        // 01:30 EST + 1 hour lands after the 02:00 -> 03:00 jump
        $this->assertSame('2024-03-10 03:30:00 EDT', ($this->tool)('2024-03-10 01:30:00', 1, 'hours', 'America/New_York', 'Y-m-d H:i:s T'));
    }

    public function test_hours_are_elapsed_time_across_the_fall_back_overlap(): void
    {
        // 00:30 EDT + 2 hours is the second 01:30 of the day, in EST
        $this->assertSame('2024-11-03 01:30:00 EST', ($this->tool)('2024-11-03 00:30:00', 2, 'hours', 'America/New_York', 'Y-m-d H:i:s T'));
    }

    public function test_days_keep_the_wall_clock_time_across_a_dst_change(): void
    {
        $this->assertSame('2024-03-10 12:00:00 EDT', ($this->tool)('2024-03-09 12:00:00', 1, 'days', 'America/New_York', 'Y-m-d H:i:s T'));
    }

    public function test_an_explicit_offset_in_the_date_wins_over_the_timezone_argument(): void
    {
        $this->assertSame('2023-01-01T13:00:00+02:00', ($this->tool)('2023-01-01T12:00:00+02:00', 1, 'hours', 'America/New_York', 'c'));
    }

    public function test_invalid_timezone(): void
    {
        $result = ($this->tool)('2023-01-01', 1, 'days', 'Invalid/Timezone');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Invalid/Timezone', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('add_time', $this->tool->getName());
        $this->assertSame('Add time periods to a date (supports days, weeks, months, years, hours, minutes, seconds)', $this->tool->getDescription());

        $this->assertSame(['date', 'amount', 'unit', 'timezone', 'format'], array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $this->tool->getProperties()));
        $this->assertSame(['date', 'amount', 'unit'], $this->tool->getRequiredProperties());

        $unit = $this->tool->getProperties()[2];
        $this->assertInstanceOf(ToolProperty::class, $unit);
        $this->assertSame(['seconds', 'minutes', 'hours', 'days', 'weeks', 'months', 'years'], $unit->getEnum());
    }
}
