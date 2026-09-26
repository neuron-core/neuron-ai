<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\GetWeekNumberTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;

class GetWeekNumberToolTest extends TestCase
{
    protected GetWeekNumberTool $tool;

    protected function setUp(): void
    {
        $this->tool = new GetWeekNumberTool();
    }

    public function test_describes_the_iso_week_of_a_date(): void
    {
        $this->assertSame([
            'week_number' => 24,
            'iso_year' => 2023,
            'day_of_week' => 4,
            'week_start' => '2023-06-12',
            'week_end' => '2023-06-18',
            'formatted' => '2023-W24',
        ], json_decode(($this->tool)('2023-06-15'), true));
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function yearBoundaries(): array
    {
        return [
            'early january in the last week of the previous iso year' => ['2021-01-03', '2020-W53', '2020-12-28', '2021-01-03'],
            'first monday of the iso year' => ['2021-01-04', '2021-W01', '2021-01-04', '2021-01-10'],
            'late december in the first week of the next iso year' => ['2024-12-30', '2025-W01', '2024-12-30', '2025-01-05'],
            'new year on a thursday' => ['2026-01-01', '2026-W01', '2025-12-29', '2026-01-04'],
            'new year on a friday' => ['2027-01-01', '2026-W53', '2026-12-28', '2027-01-03'],
            'single digit week is zero padded' => ['2023-01-09', '2023-W02', '2023-01-09', '2023-01-15'],
        ];
    }

    #[DataProvider('yearBoundaries')]
    public function test_uses_the_iso_week_numbering_year(string $date, string $formatted, string $weekStart, string $weekEnd): void
    {
        $result = json_decode(($this->tool)($date), true);

        $this->assertSame([$formatted, $weekStart, $weekEnd], [$result['formatted'], $result['week_start'], $result['week_end']]);
    }

    public function test_a_timestamp_is_resolved_in_the_requested_timezone(): void
    {
        // Sunday 2023-06-18 20:00 UTC is already Monday morning in Tokyo
        $utc = json_decode(($this->tool)('1687118400'), true);
        $tokyo = json_decode(($this->tool)('1687118400', 'Asia/Tokyo'), true);

        $this->assertSame(['2023-W24', 7], [$utc['formatted'], $utc['day_of_week']]);
        $this->assertSame(['2023-W25', 1], [$tokyo['formatted'], $tokyo['day_of_week']]);
    }

    public function test_an_invalid_date_is_reported_as_an_error(): void
    {
        $result = ($this->tool)('week-twelve');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('week-twelve', $result);
    }
}
