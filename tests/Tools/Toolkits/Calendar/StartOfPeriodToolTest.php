<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\StartOfPeriodTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StartOfPeriodToolTest extends TestCase
{
    protected StartOfPeriodTool $tool;

    protected function setUp(): void
    {
        $this->tool = new StartOfPeriodTool();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function periods(): array
    {
        return [
            'week from a wednesday' => ['2023-06-14 15:30:00', 'week', '2023-06-12 00:00:00'],
            'week from a monday' => ['2023-06-12 08:00:00', 'week', '2023-06-12 00:00:00'],
            'week from a sunday belongs to the iso week that began on monday' => ['2023-06-18 23:59:59', 'week', '2023-06-12 00:00:00'],
            'week spanning new year' => ['2021-01-02 12:00:00', 'week', '2020-12-28 00:00:00'],
            'month' => ['2023-06-30 23:59:59', 'month', '2023-06-01 00:00:00'],
            'leap february' => ['2024-02-29 10:00:00', 'month', '2024-02-01 00:00:00'],
            'first quarter first month' => ['2023-01-15 08:00:00', 'quarter', '2023-01-01 00:00:00'],
            'first quarter' => ['2023-03-31 23:59:59', 'quarter', '2023-01-01 00:00:00'],
            'second quarter' => ['2023-04-01 00:00:00', 'quarter', '2023-04-01 00:00:00'],
            'second quarter last month' => ['2023-06-30 23:59:59', 'quarter', '2023-04-01 00:00:00'],
            'third quarter' => ['2023-08-15 12:00:00', 'quarter', '2023-07-01 00:00:00'],
            'third quarter last month' => ['2023-09-30 23:59:59', 'quarter', '2023-07-01 00:00:00'],
            'fourth quarter first month' => ['2023-10-01 00:00:00', 'quarter', '2023-10-01 00:00:00'],
            'fourth quarter' => ['2023-12-31 23:59:59', 'quarter', '2023-10-01 00:00:00'],
            'year' => ['2023-12-31 23:59:59', 'year', '2023-01-01 00:00:00'],
        ];
    }

    #[DataProvider('periods')]
    public function test_returns_the_first_instant_of_the_period(string $date, string $period, string $expected): void
    {
        $this->assertSame($expected, ($this->tool)($date, $period));
    }

    public function test_a_timestamp_is_resolved_in_the_requested_timezone(): void
    {
        // 2024-01-01 00:00 UTC is still New Year's Eve 2023 in New York
        $this->assertSame('2024-01-01 00:00:00', ($this->tool)('1704067200', 'year'));
        $this->assertSame('2023-01-01 00:00:00', ($this->tool)('1704067200', 'year', 'America/New_York'));
    }

    public function test_the_week_start_keeps_the_offset_in_force_on_that_day(): void
    {
        // Sunday 2024-03-10 is the DST switch in New York: its week began on Monday in EST
        $this->assertSame('2024-03-04T00:00:00-05:00', ($this->tool)('2024-03-10 12:00:00', 'week', 'America/New_York', 'c'));
    }

    public function test_an_unsupported_period_is_reported_as_an_error(): void
    {
        $this->assertSame('Error: Unsupported period: decade', ($this->tool)('2023-06-15', 'decade'));
    }

    public function test_an_invalid_date_is_reported_as_an_error(): void
    {
        $this->assertStringStartsWith('Error: ', ($this->tool)('the day after', 'week'));
    }
}
