<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\GetWeekdayTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function json_decode;

class GetWeekdayToolTest extends TestCase
{
    protected GetWeekdayTool $tool;

    protected string $defaultTimezone;

    protected function setUp(): void
    {
        // Date strings are parsed in PHP's default timezone, so pin it to keep these cases deterministic
        $this->defaultTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->tool = new GetWeekdayTool();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function weekdays(): array
    {
        return [
            'monday' => ['2023-06-12', 'Monday', 'Mon', '1'],
            'tuesday' => ['2023-06-13', 'Tuesday', 'Tue', '2'],
            'wednesday' => ['2023-06-14', 'Wednesday', 'Wed', '3'],
            'thursday' => ['2023-06-15', 'Thursday', 'Thu', '4'],
            'friday' => ['2023-06-16', 'Friday', 'Fri', '5'],
            'saturday' => ['2023-06-17', 'Saturday', 'Sat', '6'],
            'sunday' => ['2023-06-18', 'Sunday', 'Sun', '7'],
        ];
    }

    #[DataProvider('weekdays')]
    public function test_each_format_names_the_weekday(string $date, string $name, string $short, string $isoNumber): void
    {
        $this->assertSame($name, ($this->tool)($date));
        $this->assertSame($name, ($this->tool)($date, 'name'));
        $this->assertSame($short, ($this->tool)($date, 'short'));
        $this->assertSame($isoNumber, ($this->tool)($date, 'number'));
    }

    public function test_all_gives_iso_and_us_numbering(): void
    {
        $this->assertSame(
            ['name' => 'Sunday', 'short' => 'Sun', 'number' => 7, 'iso_number' => 7, 'us_number' => 0],
            json_decode(($this->tool)('2023-06-18', 'all'), true)
        );
        $this->assertSame(
            ['name' => 'Monday', 'short' => 'Mon', 'number' => 1, 'iso_number' => 1, 'us_number' => 1],
            json_decode(($this->tool)('2023-06-12', 'all'), true)
        );
    }

    public function test_an_unknown_format_falls_back_to_the_name(): void
    {
        $this->assertSame('Thursday', ($this->tool)('2023-06-15', 'roman'));
    }

    public function test_get_weekday_with_timestamp(): void
    {
        $timestamp = '1686834000'; // 2023-06-15 13:00:00 UTC (Thursday)
        $result = ($this->tool)($timestamp);

        $this->assertSame('Thursday', $result);
    }

    public function test_get_weekday_with_timezone(): void
    {
        // Wednesday 23:00 UTC becomes Thursday 08:00 JST
        $result = ($this->tool)('2023-06-14 23:00:00', null, 'Asia/Tokyo');

        $this->assertSame('Thursday', $result);
    }

    public function test_get_weekday_with_date_time(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:45');

        $this->assertSame('Thursday', $result);
    }

    public function test_get_weekday_across_timezones(): void
    {
        // Same timestamp in different timezones
        $utc = ($this->tool)('2023-06-15 02:00:00', null, 'UTC');
        $pacific = ($this->tool)('2023-06-15 02:00:00', null, 'America/Los_Angeles');
        $sydney = ($this->tool)('2023-06-15 02:00:00', null, 'Australia/Sydney');

        $this->assertSame('Thursday', $utc);
        $this->assertSame('Wednesday', $pacific);
        $this->assertSame('Thursday', $sydney);
    }

    public function test_get_weekday_timezone_conversion(): void
    {
        // Wednesday 22:00 UTC
        $utc = ($this->tool)('2023-06-14 22:00:00 UTC', 'name', 'UTC');

        // Same moment but displayed in Tokyo time (Thursday 07:00 JST)
        $tokyo = ($this->tool)('2023-06-14 22:00:00 UTC', 'name', 'Asia/Tokyo');

        $this->assertSame('Wednesday', $utc);
        $this->assertSame('Thursday', $tokyo);
    }

    public function test_an_invalid_date_is_reported_as_an_error_naming_it(): void
    {
        $result = ($this->tool)('invalid-date');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('invalid-date', $result);
    }

    public function test_an_invalid_timezone_is_reported_as_an_error_naming_it(): void
    {
        $result = ($this->tool)('2023-06-15', null, 'Invalid/Timezone');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Invalid/Timezone', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('get_weekday', $this->tool->getName());
        $this->assertSame('Get the day of week name and number for a given date', $this->tool->getDescription());

        $this->assertSame(
            ['date', 'format', 'timezone'],
            array_map(fn (ToolPropertyInterface $property): string => $property->getName(), $this->tool->getProperties())
        );
        $this->assertSame(['date'], $this->tool->getRequiredProperties());
    }
}
