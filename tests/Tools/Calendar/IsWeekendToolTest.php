<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\IsWeekendTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;

class IsWeekendToolTest extends TestCase
{
    private IsWeekendTool $tool;

    protected function setUp(): void
    {
        $this->tool = new IsWeekendTool();
    }

    public function testSaturdayIsWeekend(): void
    {
        $result = ($this->tool)('2023-06-17'); // Saturday

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['is_weekend']);
        $this->assertEquals('Saturday', $data['day_of_week']);
        $this->assertEquals(6, $data['day_number']);
    }

    public function testSundayIsWeekend(): void
    {
        $result = ($this->tool)('2023-06-18'); // Sunday

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['is_weekend']);
        $this->assertEquals('Sunday', $data['day_of_week']);
        $this->assertEquals(7, $data['day_number']);
    }

    public function testMondayIsNotWeekend(): void
    {
        $result = ($this->tool)('2023-06-12'); // Monday

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertFalse($data['is_weekend']);
        $this->assertEquals('Monday', $data['day_of_week']);
        $this->assertEquals(1, $data['day_number']);
    }

    public function testTuesdayIsNotWeekend(): void
    {
        $result = ($this->tool)('2023-06-13'); // Tuesday

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertFalse($data['is_weekend']);
        $this->assertEquals('Tuesday', $data['day_of_week']);
        $this->assertEquals(2, $data['day_number']);
    }

    public function testWednesdayIsNotWeekend(): void
    {
        $result = ($this->tool)('2023-06-14'); // Wednesday

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertFalse($data['is_weekend']);
        $this->assertEquals('Wednesday', $data['day_of_week']);
        $this->assertEquals(3, $data['day_number']);
    }

    public function testThursdayIsNotWeekend(): void
    {
        $result = ($this->tool)('2023-06-15'); // Thursday

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertFalse($data['is_weekend']);
        $this->assertEquals('Thursday', $data['day_of_week']);
        $this->assertEquals(4, $data['day_number']);
    }

    public function testFridayIsNotWeekend(): void
    {
        $result = ($this->tool)('2023-06-16'); // Friday

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertFalse($data['is_weekend']);
        $this->assertEquals('Friday', $data['day_of_week']);
        $this->assertEquals(5, $data['day_number']);
    }

    public function testAllWeekdays(): void
    {
        $weekdays = [
            '2023-06-12' => ['Monday', 1, false],    // Monday
            '2023-06-13' => ['Tuesday', 2, false],   // Tuesday
            '2023-06-14' => ['Wednesday', 3, false], // Wednesday
            '2023-06-15' => ['Thursday', 4, false],  // Thursday
            '2023-06-16' => ['Friday', 5, false],    // Friday
            '2023-06-17' => ['Saturday', 6, true],   // Saturday
            '2023-06-18' => ['Sunday', 7, true],     // Sunday
        ];

        foreach ($weekdays as $date => [$expectedDay, $expectedNumber, $expectedWeekend]) {
            $result = ($this->tool)($date);
            $data = json_decode($result, true);

            $this->assertEquals($expectedWeekend, $data['is_weekend'], "Failed for $date");
            $this->assertEquals($expectedDay, $data['day_of_week'], "Failed for $date");
            $this->assertEquals($expectedNumber, $data['day_number'], "Failed for $date");
        }
    }

    public function testWithTimestamp(): void
    {
        $timestamp = '1687017600'; // 2023-06-17 20:00:00 UTC (Saturday)
        $result = ($this->tool)($timestamp);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['is_weekend']);
        $this->assertEquals('Saturday', $data['day_of_week']);
    }

    public function testWithDateTime(): void
    {
        $result = ($this->tool)('2023-06-17 14:30:45'); // Saturday

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['is_weekend']);
        $this->assertEquals('Saturday', $data['day_of_week']);
    }

    public function testWithTimezone(): void
    {
        // Friday 23:00 UTC becomes Saturday in many timezones
        $utcResult = ($this->tool)('2023-06-16 23:00:00', 'UTC');
        $tokyoResult = ($this->tool)('2023-06-16 23:00:00', 'Asia/Tokyo');

        $utcData = json_decode($utcResult, true);
        $tokyoData = json_decode($tokyoResult, true);

        $this->assertFalse($utcData['is_weekend']); // Friday in UTC
        $this->assertTrue($tokyoData['is_weekend']); // Saturday in Tokyo (UTC+9)
    }

    public function testTimezoneConversionMidnight(): void
    {
        // Test midnight conversions
        $result1 = ($this->tool)('2023-06-17 00:00:00', 'UTC'); // Saturday midnight UTC
        $result2 = ($this->tool)('2023-06-16 15:00:00', 'America/Los_Angeles'); // Friday 3PM PDT = Saturday 22:00 UTC

        $data1 = json_decode($result1, true);
        $data2 = json_decode($result2, true);

        $this->assertTrue($data1['is_weekend']); // Saturday in UTC
        $this->assertFalse($data2['is_weekend']); // Friday in PDT
    }

    public function testJsonStructure(): void
    {
        $result = ($this->tool)('2023-06-17');

        $data = json_decode($result, true);
        $this->assertIsArray($data);

        // Check all required keys are present
        $expectedKeys = ['is_weekend', 'day_of_week', 'day_number'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        // Check data types
        $this->assertIsBool($data['is_weekend']);
        $this->assertIsString($data['day_of_week']);
        $this->assertIsInt($data['day_number']);
    }

    public function testInvalidDate(): void
    {
        $result = ($this->tool)('invalid-date');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testInvalidTimezone(): void
    {
        $result = ($this->tool)('2023-06-17', 'Invalid/Timezone');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testToolProperties(): void
    {
        $this->assertEquals('is_weekend', $this->tool->getName());
        $this->assertEquals('Check if a given date falls on a weekend (Saturday or Sunday)', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(2, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('date', $propertyNames);
        $this->assertContains('timezone', $propertyNames);
    }
}
