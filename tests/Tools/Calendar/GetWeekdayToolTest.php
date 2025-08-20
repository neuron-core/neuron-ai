<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\GetWeekdayTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

class GetWeekdayToolTest extends TestCase
{
    private GetWeekdayTool $tool;

    protected function setUp(): void
    {
        $this->tool = new GetWeekdayTool();
    }

    public function testGetWeekdayName(): void
    {
        $result = ($this->tool)('2023-06-15'); // Thursday

        $this->assertEquals('Thursday', $result);
    }

    public function testGetWeekdayShort(): void
    {
        $result = ($this->tool)('2023-06-15', 'short'); // Thursday

        $this->assertEquals('Thu', $result);
    }

    public function testGetWeekdayNumber(): void
    {
        $result = ($this->tool)('2023-06-15', 'number'); // Thursday

        $this->assertEquals('4', $result); // ISO 8601 (1=Monday, 7=Sunday)
    }

    public function testGetWeekdayAll(): void
    {
        $result = ($this->tool)('2023-06-18', 'all'); // Sunday

        $data = \json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals('Sunday', $data['name']);
        $this->assertEquals('Sun', $data['short']);
        $this->assertEquals(7, $data['number']); // ISO 8601
        $this->assertEquals(7, $data['iso_number']); // ISO 8601
        $this->assertEquals(0, $data['us_number']); // US format (0=Sunday)
    }

    public function testGetWeekdayForAllDays(): void
    {
        $dates = [
            '2023-06-12' => 'Monday',    // Monday
            '2023-06-13' => 'Tuesday',   // Tuesday
            '2023-06-14' => 'Wednesday', // Wednesday
            '2023-06-15' => 'Thursday',  // Thursday
            '2023-06-16' => 'Friday',    // Friday
            '2023-06-17' => 'Saturday',  // Saturday
            '2023-06-18' => 'Sunday',    // Sunday
        ];

        foreach ($dates as $date => $expectedDay) {
            $result = ($this->tool)($date);
            $this->assertEquals($expectedDay, $result);
        }
    }

    public function testGetWeekdayWithTimestamp(): void
    {
        $timestamp = '1686834000'; // 2023-06-15 14:00:00 UTC (Thursday)
        $result = ($this->tool)($timestamp);

        $this->assertEquals('Thursday', $result);
    }

    public function testGetWeekdayWithTimezone(): void
    {
        // Wednesday 23:00 UTC becomes Thursday 03:00 JST
        $result = ($this->tool)('2023-06-14 23:00:00', null, 'Asia/Tokyo');

        $this->assertEquals('Thursday', $result);
    }

    public function testGetWeekdayNumbersForAllDays(): void
    {
        $dates = [
            '2023-06-12' => 1, // Monday
            '2023-06-13' => 2, // Tuesday
            '2023-06-14' => 3, // Wednesday
            '2023-06-15' => 4, // Thursday
            '2023-06-16' => 5, // Friday
            '2023-06-17' => 6, // Saturday
            '2023-06-18' => 7, // Sunday
        ];

        foreach ($dates as $date => $expectedNumber) {
            $result = ($this->tool)($date, 'number');
            $this->assertEquals((string) $expectedNumber, $result);
        }
    }

    public function testGetWeekdayShortForAllDays(): void
    {
        $dates = [
            '2023-06-12' => 'Mon',
            '2023-06-13' => 'Tue',
            '2023-06-14' => 'Wed',
            '2023-06-15' => 'Thu',
            '2023-06-16' => 'Fri',
            '2023-06-17' => 'Sat',
            '2023-06-18' => 'Sun',
        ];

        foreach ($dates as $date => $expectedShort) {
            $result = ($this->tool)($date, 'short');
            $this->assertEquals($expectedShort, $result);
        }
    }

    public function testGetWeekdayWithDateTime(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:45');

        $this->assertEquals('Thursday', $result);
    }

    public function testGetWeekdayAcrossTimezones(): void
    {
        // Same timestamp in different timezones
        $utc = ($this->tool)('2023-06-15 02:00:00', null, 'UTC');
        $pacific = ($this->tool)('2023-06-15 02:00:00', null, 'America/Los_Angeles');
        $sydney = ($this->tool)('2023-06-15 02:00:00', null, 'Australia/Sydney');

        $this->assertEquals('Thursday', $utc);
        $this->assertEquals('Wednesday', $pacific);
        $this->assertEquals('Thursday', $sydney);
    }

    public function testGetWeekdayTimezoneConversion(): void
    {
        // Wednesday 22:00 UTC
        $utc = ($this->tool)('2023-06-14 22:00:00 UTC', 'name', 'UTC');

        // Same moment but displayed in Tokyo time (Thursday 07:00 JST)
        $tokyo = ($this->tool)('2023-06-14 22:00:00 UTC', 'name', 'Asia/Tokyo');

        $this->assertEquals('Wednesday', $utc);
        $this->assertEquals('Thursday', $tokyo);
    }

    public function testInvalidDate(): void
    {
        $result = ($this->tool)('invalid-date');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testInvalidTimezone(): void
    {
        $result = ($this->tool)('2023-06-15', null, 'Invalid/Timezone');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testDefaultFormat(): void
    {
        // Default format should be 'name'
        $result1 = ($this->tool)('2023-06-15');
        $result2 = ($this->tool)('2023-06-15', 'name');

        $this->assertEquals($result1, $result2);
        $this->assertEquals('Thursday', $result1);
    }

    public function testToolProperties(): void
    {
        $this->assertEquals('get_weekday', $this->tool->getName());
        $this->assertEquals('Get the day of week name and number for a given date', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(3, $properties);

        $propertyNames = \array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('date', $propertyNames);
        $this->assertContains('format', $propertyNames);
        $this->assertContains('timezone', $propertyNames);
    }
}
