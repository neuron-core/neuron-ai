<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\SubtractTimeTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;

class SubtractTimeToolTest extends TestCase
{
    protected SubtractTimeTool $tool;

    protected function setUp(): void
    {
        $this->tool = new SubtractTimeTool();
    }

    public function test_subtract_seconds(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:30', 30, 'seconds');

        $this->assertSame('2023-01-01 12:00:00', $result);
    }

    public function test_subtract_minutes(): void
    {
        $result = ($this->tool)('2023-01-01 12:45:00', 45, 'minutes');

        $this->assertSame('2023-01-01 12:00:00', $result);
    }

    public function test_subtract_hours(): void
    {
        $result = ($this->tool)('2023-01-01 18:00:00', 6, 'hours');

        $this->assertSame('2023-01-01 12:00:00', $result);
    }

    public function test_subtract_days(): void
    {
        $result = ($this->tool)('2023-01-06', 5, 'days');

        $this->assertSame('2023-01-01 00:00:00', $result);
    }

    public function test_subtract_weeks(): void
    {
        $result = ($this->tool)('2023-01-15', 2, 'weeks');

        $this->assertSame('2023-01-01 00:00:00', $result);
    }

    public function test_subtract_months(): void
    {
        $result = ($this->tool)('2023-04-15', 3, 'months');

        $this->assertSame('2023-01-15 00:00:00', $result);
    }

    public function test_subtract_years(): void
    {
        $result = ($this->tool)('2023-02-28', 1, 'years');

        $this->assertSame('2022-02-28 00:00:00', $result);
    }

    public function test_subtract_with_timestamp(): void
    {
        $timestamp = '1672617600'; // 2023-01-02 00:00:00 UTC
        $result = ($this->tool)($timestamp, 1, 'days');

        $this->assertSame('2023-01-01 00:00:00', $result);
    }

    public function test_subtract_with_timezone(): void
    {
        $result = ($this->tool)('2023-01-02 00:00:00', 12, 'hours', 'America/New_York');

        $this->assertSame('2023-01-01 12:00:00', $result);
    }

    public function test_subtract_with_custom_format(): void
    {
        $result = ($this->tool)('2023-01-02', 1, 'days', null, 'Y/m/d');

        $this->assertSame('2023/01/01', $result);
    }

    public function test_subtract_float_amount(): void
    {
        $result = ($this->tool)('2023-01-01 13:30:00', 1.5, 'hours');

        $this->assertSame('2023-01-01 12:00:00', $result);
    }

    public function test_subtract_across_month_boundary(): void
    {
        $result = ($this->tool)('2023-02-02', 5, 'days');

        $this->assertSame('2023-01-28 00:00:00', $result);
    }

    public function test_subtract_across_year_boundary(): void
    {
        $result = ($this->tool)('2023-01-02', 3, 'days');

        $this->assertSame('2022-12-30 00:00:00', $result);
    }

    public function test_subtract_from_leap_year_february(): void
    {
        $result = ($this->tool)('2024-02-29', 1, 'days'); // 2024 is leap year

        $this->assertSame('2024-02-28 00:00:00', $result);
    }

    public function test_subtract_large_amount(): void
    {
        $result = ($this->tool)('2023-01-01', 365, 'days');

        $this->assertSame('2022-01-01 00:00:00', $result);
    }

    public function test_invalid_date(): void
    {
        $result = ($this->tool)('invalid-date', 1, 'days');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('invalid-date', $result);
    }

    public function test_invalid_unit(): void
    {
        $this->assertSame('Error: Unsupported unit: decades', ($this->tool)('2023-01-01', 1, 'decades'));
    }

    public function test_a_negative_amount_is_rejected(): void
    {
        $this->assertStringStartsWith('Error: ', ($this->tool)('2023-01-10', -2, 'hours'));
    }

    /**
     * @return array<string, array{float, string, string}>
     */
    public static function fractionalAmounts(): array
    {
        return [
            'half a minute' => [0.5, 'minutes', '2023-01-01 11:59:30'],
            'a quarter of an hour' => [0.25, 'hours', '2023-01-01 11:45:00'],
            'a day and a half' => [1.5, 'days', '2022-12-31 00:00:00'],
        ];
    }

    #[DataProvider('fractionalAmounts')]
    public function test_fractional_amounts_subtract_the_remainder_in_seconds(float $amount, string $unit, string $expected): void
    {
        $this->assertSame($expected, ($this->tool)('2023-01-01 12:00:00', $amount, $unit));
    }

    public function test_hours_are_elapsed_time_across_the_spring_forward_gap(): void
    {
        $this->assertSame('2024-03-10 01:30:00 EST', ($this->tool)('2024-03-10 03:30:00', 1, 'hours', 'America/New_York', 'Y-m-d H:i:s T'));
    }

    public function test_weeks_keep_the_wall_clock_time_across_a_dst_change(): void
    {
        $this->assertSame('2024-11-01 09:00:00 EDT', ($this->tool)('2024-11-08 09:00:00', 1, 'weeks', 'America/New_York', 'Y-m-d H:i:s T'));
    }

    public function test_invalid_timezone(): void
    {
        $result = ($this->tool)('2023-01-01', 1, 'days', 'Invalid/Timezone');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Invalid/Timezone', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('subtract_time', $this->tool->getName());
        $this->assertSame('Subtract time periods from a date (supports days, weeks, months, years, hours, minutes, seconds)', $this->tool->getDescription());

        $this->assertSame(
            ['date', 'amount', 'unit', 'timezone', 'format'],
            array_map(fn (ToolPropertyInterface $property): string => $property->getName(), $this->tool->getProperties())
        );
        $this->assertSame(['date', 'amount', 'unit'], $this->tool->getRequiredProperties());
    }
}
