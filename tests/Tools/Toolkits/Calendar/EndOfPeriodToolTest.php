<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\EndOfPeriodTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EndOfPeriodToolTest extends TestCase
{
    protected EndOfPeriodTool $tool;

    protected function setUp(): void
    {
        $this->tool = new EndOfPeriodTool();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function periods(): array
    {
        return [
            'week from a monday' => ['2023-06-12 00:00:00', 'week', '2023-06-18 23:59:59'],
            'week from a sunday' => ['2023-06-18 15:00:00', 'week', '2023-06-18 23:59:59'],
            'week spanning new year' => ['2024-12-30 09:00:00', 'week', '2025-01-05 23:59:59'],
            'thirty day month' => ['2023-04-01 00:00:00', 'month', '2023-04-30 23:59:59'],
            'thirty one day month' => ['2023-01-31 12:00:00', 'month', '2023-01-31 23:59:59'],
            'february' => ['2023-02-10 00:00:00', 'month', '2023-02-28 23:59:59'],
            'leap february' => ['2024-02-10 00:00:00', 'month', '2024-02-29 23:59:59'],
            'first quarter' => ['2024-02-10 00:00:00', 'quarter', '2024-03-31 23:59:59'],
            'first quarter last month' => ['2024-03-31 23:59:59', 'quarter', '2024-03-31 23:59:59'],
            'second quarter' => ['2023-04-01 00:00:00', 'quarter', '2023-06-30 23:59:59'],
            'second quarter last month' => ['2023-06-15 00:00:00', 'quarter', '2023-06-30 23:59:59'],
            'third quarter' => ['2023-07-31 00:00:00', 'quarter', '2023-09-30 23:59:59'],
            'third quarter last month' => ['2023-09-30 23:59:59', 'quarter', '2023-09-30 23:59:59'],
            'fourth quarter' => ['2023-10-31 00:00:00', 'quarter', '2023-12-31 23:59:59'],
            'fourth quarter last month' => ['2023-12-01 00:00:00', 'quarter', '2023-12-31 23:59:59'],
            'year' => ['2023-01-01 00:00:00', 'year', '2023-12-31 23:59:59'],
        ];
    }

    #[DataProvider('periods')]
    public function test_returns_the_last_second_of_the_period(string $date, string $period, string $expected): void
    {
        $this->assertSame($expected, ($this->tool)($date, $period));
    }

    public function test_a_timestamp_is_resolved_in_the_requested_timezone(): void
    {
        // 2024-01-01 00:00 UTC is still New Year's Eve 2023 in New York
        $this->assertSame('2024-01-31 23:59:59', ($this->tool)('1704067200', 'month'));
        $this->assertSame('2023-12-31 23:59:59', ($this->tool)('1704067200', 'month', 'America/New_York'));
    }

    public function test_the_week_end_uses_the_offset_in_force_on_that_day(): void
    {
        // Sunday 2024-11-03 is the day New York falls back from EDT to EST
        $this->assertSame('2024-11-03T23:59:59-05:00', ($this->tool)('2024-10-28 12:00:00', 'week', 'America/New_York', 'c'));
    }

    public function test_an_unsupported_period_is_reported_as_an_error(): void
    {
        $this->assertSame('Error: Unsupported period: fortnight', ($this->tool)('2023-06-15', 'fortnight'));
    }

    public function test_an_invalid_timezone_is_reported_as_an_error(): void
    {
        $this->assertStringStartsWith('Error: ', ($this->tool)('2023-06-15', 'month', 'Europe/Atlantis'));
    }
}
