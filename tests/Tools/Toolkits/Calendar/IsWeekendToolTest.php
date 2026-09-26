<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\IsWeekendTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;

class IsWeekendToolTest extends TestCase
{
    protected IsWeekendTool $tool;

    protected function setUp(): void
    {
        $this->tool = new IsWeekendTool();
    }

    /**
     * @return array<string, array{string, bool, string, int}>
     */
    public static function daysOfTheWeek(): array
    {
        return [
            'monday' => ['2023-06-12', false, 'Monday', 1],
            'tuesday' => ['2023-06-13', false, 'Tuesday', 2],
            'wednesday' => ['2023-06-14', false, 'Wednesday', 3],
            'thursday' => ['2023-06-15', false, 'Thursday', 4],
            'friday' => ['2023-06-16', false, 'Friday', 5],
            'saturday' => ['2023-06-17', true, 'Saturday', 6],
            'sunday' => ['2023-06-18', true, 'Sunday', 7],
        ];
    }

    #[DataProvider('daysOfTheWeek')]
    public function test_only_saturday_and_sunday_are_weekend_days(string $date, bool $weekend, string $name, int $number): void
    {
        $this->assertSame(
            ['is_weekend' => $weekend, 'day_of_week' => $name, 'day_number' => $number],
            json_decode(($this->tool)($date), true)
        );
    }

    public function test_the_weekend_starts_at_midnight_on_saturday(): void
    {
        $this->assertFalse(json_decode(($this->tool)('2023-06-16 23:59:59'), true)['is_weekend']);
        $this->assertTrue(json_decode(($this->tool)('2023-06-17 00:00:00'), true)['is_weekend']);
    }

    public function test_the_weekend_ends_at_midnight_on_monday(): void
    {
        $this->assertTrue(json_decode(($this->tool)('2023-06-18 23:59:59'), true)['is_weekend']);
        $this->assertFalse(json_decode(($this->tool)('2023-06-19 00:00:00'), true)['is_weekend']);
    }

    public function test_a_timestamp_is_resolved_in_the_requested_timezone(): void
    {
        $timestamp = '1687046400'; // 2023-06-18 00:00:00 UTC, still Saturday evening in Los Angeles

        $this->assertSame('Sunday', json_decode(($this->tool)($timestamp), true)['day_of_week']);
        $this->assertSame('Saturday', json_decode(($this->tool)($timestamp, 'America/Los_Angeles'), true)['day_of_week']);
    }

    public function test_a_date_string_is_read_as_utc_and_converted_to_the_requested_timezone(): void
    {
        // Friday 23:00 UTC is already Saturday 08:00 in Tokyo
        $utc = json_decode(($this->tool)('2023-06-16 23:00:00', 'UTC'), true);
        $tokyo = json_decode(($this->tool)('2023-06-16 23:00:00', 'Asia/Tokyo'), true);

        $this->assertFalse($utc['is_weekend']);
        $this->assertTrue($tokyo['is_weekend']);
        $this->assertSame('Saturday', $tokyo['day_of_week']);
    }

    public function test_an_explicit_offset_in_the_date_is_honoured(): void
    {
        // Saturday 01:00 in Tokyo is Friday 16:00 UTC
        $this->assertFalse(json_decode(($this->tool)('2023-06-17T01:00:00+09:00'), true)['is_weekend']);
    }

    public function test_invalid_date(): void
    {
        $this->assertStringStartsWith('Error: ', ($this->tool)('invalid-date'));
    }

    public function test_invalid_timezone(): void
    {
        $this->assertStringStartsWith('Error: ', ($this->tool)('2023-06-17', 'Invalid/Timezone'));
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('is_weekend', $this->tool->getName());
        $this->assertSame('Check if a given date falls on a weekend (Saturday or Sunday)', $this->tool->getDescription());
        $this->assertSame(['date', 'timezone'], array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $this->tool->getProperties()));
        $this->assertSame(['date'], $this->tool->getRequiredProperties());
    }
}
