<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\CompareDatesTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;

class CompareDatesToolTest extends TestCase
{
    protected CompareDatesTool $tool;

    protected function setUp(): void
    {
        $this->tool = new CompareDatesTool();
    }

    public function test_reports_the_full_relationship_between_two_dates(): void
    {
        $this->assertSame([
            'date1' => '2023-06-15 10:00:00',
            'date2' => '2023-06-16 09:30:00',
            'comparison' => 'before',
            'is_before' => true,
            'is_after' => false,
            'is_equal' => false,
            'precision' => 'second',
        ], json_decode(($this->tool)('2023-06-15 10:00:00', '2023-06-16 09:30:00'), true));
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function comparisons(): array
    {
        return [
            'one second apart' => ['2023-06-15 10:00:01', '2023-06-15 10:00:00', 'second', 'after'],
            'identical instants' => ['2023-06-15 10:00:00', '2023-06-15 10:00:00', 'second', 'equal'],
            'same minute' => ['2023-06-15 10:15:59', '2023-06-15 10:15:00', 'minute', 'equal'],
            'next minute' => ['2023-06-15 10:15:59', '2023-06-15 10:16:00', 'minute', 'before'],
            'same hour' => ['2023-06-15 10:59:59', '2023-06-15 10:00:00', 'hour', 'equal'],
            'next hour' => ['2023-06-15 10:59:59', '2023-06-15 11:00:00', 'hour', 'before'],
            'same day' => ['2023-06-15 23:59:59', '2023-06-15 00:00:00', 'day', 'equal'],
            'next day' => ['2023-06-16 00:00:00', '2023-06-15 23:59:59', 'day', 'after'],
            'same month' => ['2023-02-28 23:00:00', '2023-02-01 00:00:00', 'month', 'equal'],
            'next month' => ['2023-03-01 00:00:00', '2023-02-28 23:59:59', 'month', 'after'],
            'same year' => ['2023-12-31 23:59:59', '2023-01-01 00:00:00', 'year', 'equal'],
            'next year' => ['2024-01-01 00:00:00', '2023-12-31 23:59:59', 'year', 'after'],
        ];
    }

    #[DataProvider('comparisons')]
    public function test_compares_at_the_requested_precision(string $date1, string $date2, string $precision, string $expected): void
    {
        $result = json_decode(($this->tool)($date1, $date2, null, $precision), true);

        $this->assertSame($expected, $result['comparison']);
        $this->assertSame($expected === 'before', $result['is_before']);
        $this->assertSame($expected === 'after', $result['is_after']);
        $this->assertSame($expected === 'equal', $result['is_equal']);
    }

    public function test_day_precision_is_evaluated_in_the_requested_timezone(): void
    {
        // 13:00 and 23:00 UTC on June 15th: the same UTC day, but 22:00 on the 15th and 08:00 on the 16th in Tokyo
        $utc = json_decode(($this->tool)('1686834000', '1686870000', 'UTC', 'day'), true);
        $tokyo = json_decode(($this->tool)('1686834000', '1686870000', 'Asia/Tokyo', 'day'), true);

        $this->assertSame('equal', $utc['comparison']);
        $this->assertSame('before', $tokyo['comparison']);
        $this->assertSame(['2023-06-15 00:00:00', '2023-06-16 00:00:00'], [$tokyo['date1'], $tokyo['date2']]);
    }

    public function test_the_same_instant_written_in_different_offsets_is_equal(): void
    {
        $result = json_decode(($this->tool)('2023-06-15T12:00:00+02:00', '2023-06-15T10:00:00Z'), true);

        $this->assertTrue($result['is_equal']);
    }

    public function test_an_invalid_date_is_reported_as_an_error(): void
    {
        $result = ($this->tool)('2023-06-15', 'not-a-date');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('not-a-date', $result);
    }

    public function test_an_invalid_timezone_is_reported_as_an_error(): void
    {
        $result = ($this->tool)('2023-06-15', '2023-06-16', 'Mars/Olympus_Mons');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Mars/Olympus_Mons', $result);
    }
}
