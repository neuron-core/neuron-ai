<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\IsDateInRangeTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;

class IsDateInRangeToolTest extends TestCase
{
    protected IsDateInRangeTool $tool;

    protected function setUp(): void
    {
        $this->tool = new IsDateInRangeTool();
    }

    public function test_reports_the_position_of_a_date_inside_the_range(): void
    {
        $this->assertSame([
            'date' => '2023-06-15 00:00:00',
            'start_date' => '2023-06-01 00:00:00',
            'end_date' => '2023-06-30 00:00:00',
            'is_in_range' => true,
            'is_before_range' => false,
            'is_after_range' => false,
            'days_from_start' => 14,
            'precision' => 'second',
        ], json_decode(($this->tool)('2023-06-15', '2023-06-01', '2023-06-30'), true));
    }

    /**
     * @return array<string, array{string, bool, bool, bool, int}>
     */
    public static function positions(): array
    {
        return [
            'on the start boundary' => ['2023-06-01 00:00:00', true, false, false, 0],
            'on the end boundary' => ['2023-06-30 00:00:00', true, false, false, 29],
            'one second before the start' => ['2023-05-31 23:59:59', false, true, false, 0],
            'one second after the end' => ['2023-06-30 00:00:01', false, false, true, 29],
            'weeks before the start' => ['2023-05-20 00:00:00', false, true, false, -12],
        ];
    }

    #[DataProvider('positions')]
    public function test_the_range_is_inclusive_at_second_precision(string $date, bool $inRange, bool $before, bool $after, int $daysFromStart): void
    {
        $result = json_decode(($this->tool)($date, '2023-06-01 00:00:00', '2023-06-30 00:00:00'), true);

        $this->assertSame(
            [$inRange, $before, $after, $daysFromStart],
            [$result['is_in_range'], $result['is_before_range'], $result['is_after_range'], $result['days_from_start']]
        );
    }

    public function test_day_precision_includes_the_whole_last_day(): void
    {
        $second = json_decode(($this->tool)('2023-06-30 18:00:00', '2023-06-01', '2023-06-30'), true);
        $day = json_decode(($this->tool)('2023-06-30 18:00:00', '2023-06-01', '2023-06-30', null, 'day'), true);

        $this->assertFalse($second['is_in_range']);
        $this->assertTrue($second['is_after_range']);
        $this->assertTrue($day['is_in_range']);
        $this->assertSame('2023-06-30 23:59:59', $day['end_date']);
    }

    /**
     * @return array<string, array{string, string, string, string, array{string, string, string}}>
     */
    public static function precisions(): array
    {
        return [
            'minute' => ['2023-06-15 10:45:30', '2023-06-15 10:45:10', '2023-06-15 10:45:20', 'minute', ['2023-06-15 10:45:00', '2023-06-15 10:45:00', '2023-06-15 10:45:59']],
            'hour' => ['2023-06-15 10:45:30', '2023-06-15 10:10:00', '2023-06-15 10:20:00', 'hour', ['2023-06-15 10:00:00', '2023-06-15 10:00:00', '2023-06-15 10:59:59']],
            'month' => ['2023-02-20', '2023-02-01', '2023-02-05', 'month', ['2023-02-01 00:00:00', '2023-02-01 00:00:00', '2023-02-28 23:59:59']],
            'leap month' => ['2024-02-29 12:00', '2024-02-01', '2024-02-05', 'month', ['2024-02-01 00:00:00', '2024-02-01 00:00:00', '2024-02-29 23:59:59']],
            'year' => ['2023-12-31 23:00', '2023-03-01', '2023-03-01', 'year', ['2023-01-01 00:00:00', '2023-01-01 00:00:00', '2023-12-31 23:59:59']],
        ];
    }

    /**
     * @param array{string, string, string} $normalized
     */
    #[DataProvider('precisions')]
    public function test_coarser_precision_widens_the_range_to_whole_units(string $date, string $start, string $end, string $precision, array $normalized): void
    {
        $result = json_decode(($this->tool)($date, $start, $end, null, $precision), true);

        $this->assertTrue($result['is_in_range']);
        $this->assertSame($normalized, [$result['date'], $result['start_date'], $result['end_date']]);
        $this->assertSame($precision, $result['precision']);
    }

    public function test_timestamps_are_normalized_in_the_requested_timezone(): void
    {
        // 23:00 UTC on June 15th is already June 16th in Tokyo
        $utc = json_decode(($this->tool)('1686870000', '2023-06-16', '2023-06-16', 'UTC', 'day'), true);
        $tokyo = json_decode(($this->tool)('1686870000', '2023-06-16', '2023-06-16', 'Asia/Tokyo', 'day'), true);

        $this->assertFalse($utc['is_in_range']);
        $this->assertTrue($utc['is_before_range']);
        $this->assertTrue($tokyo['is_in_range']);
    }

    public function test_timestamp_boundaries_are_read_in_the_requested_timezone(): void
    {
        // The whole UTC day of June 15th spans 09:00 June 15th to 08:59:59 June 16th in Tokyo.
        $result = json_decode(($this->tool)('2023-06-16 08:00:00', '1686787200', '1686873599', 'Asia/Tokyo'), true);

        $this->assertSame('2023-06-15 09:00:00', $result['start_date']);
        $this->assertSame('2023-06-16 08:59:59', $result['end_date']);
        $this->assertTrue($result['is_in_range']);
    }

    public function test_an_invalid_boundary_is_reported_as_an_error(): void
    {
        $result = ($this->tool)('2023-06-15', '2023-06-01', 'end-of-june');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('end-of-june', $result);
    }

    public function test_an_invalid_timezone_is_reported_as_an_error(): void
    {
        $this->assertStringStartsWith('Error: ', ($this->tool)('2023-06-15', '2023-06-01', '2023-06-30', 'Nowhere/City'));
    }
}
