<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\DateDifferenceTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;

class DateDifferenceToolTest extends TestCase
{
    private DateDifferenceTool $tool;

    protected function setUp(): void
    {
        $this->tool = new DateDifferenceTool();
    }

    public function testDateDifferenceInDays(): void
    {
        $result = ($this->tool)('2023-01-01', '2023-01-10');

        $this->assertEquals('9', $result);
    }

    public function testDateDifferenceInSeconds(): void
    {
        $result = ($this->tool)('2023-01-01 10:00:00', '2023-01-01 10:01:30', 'seconds');

        $this->assertEquals('90', $result);
    }

    public function testDateDifferenceInMinutes(): void
    {
        $result = ($this->tool)('2023-01-01 10:00:00', '2023-01-01 11:30:00', 'minutes');

        $this->assertEquals('90', $result);
    }

    public function testDateDifferenceInHours(): void
    {
        $result = ($this->tool)('2023-01-01 10:00:00', '2023-01-02 14:00:00', 'hours');

        $this->assertEquals('28', $result);
    }

    public function testDateDifferenceInWeeks(): void
    {
        $result = ($this->tool)('2023-01-01', '2023-01-15', 'weeks');

        $this->assertEquals('2', $result);
    }

    public function testDateDifferenceInMonths(): void
    {
        $result = ($this->tool)('2023-01-01', '2023-07-01', 'months');

        $this->assertEquals('6', $result);
    }

    public function testDateDifferenceInYears(): void
    {
        $result = ($this->tool)('2020-01-01', '2023-01-01', 'years');

        $this->assertEquals('3', $result);
    }

    public function testDateDifferenceAll(): void
    {
        $result = ($this->tool)('2022-01-15 10:30:45', '2023-03-20 14:45:30', 'all');

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('years', $data);
        $this->assertArrayHasKey('months', $data);
        $this->assertArrayHasKey('days', $data);
        $this->assertArrayHasKey('hours', $data);
        $this->assertArrayHasKey('minutes', $data);
        $this->assertArrayHasKey('seconds', $data);
        $this->assertArrayHasKey('total_days', $data);
        $this->assertArrayHasKey('total_seconds', $data);

        $this->assertEquals(1, $data['years']);
        $this->assertEquals(2, $data['months']);
    }

    public function testDateDifferenceWithTimestamps(): void
    {
        $start = '1672531200'; // 2023-01-01 00:00:00 UTC
        $end = '1672617600';   // 2023-01-02 00:00:00 UTC

        $result = ($this->tool)($start, $end);

        $this->assertEquals('1', $result);
    }

    public function testDateDifferenceWithTimezone(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', '2023-01-02 12:00:00', 'hours', 'America/New_York');

        $this->assertEquals('24', $result);
    }

    public function testDateDifferenceReversedDates(): void
    {
        $result = ($this->tool)('2023-01-10', '2023-01-01');

        $this->assertEquals('9', $result); // Should return absolute difference
    }

    public function testDateDifferenceNegativeResult(): void
    {
        $result1 = ($this->tool)('2023-01-01', '2023-01-10', 'seconds');
        $result2 = ($this->tool)('2023-01-10', '2023-01-01', 'seconds');

        // Both should return the same absolute value
        $this->assertEquals($result1, $result2);
    }

    public function testInvalidStartDate(): void
    {
        $result = ($this->tool)('invalid-date', '2023-01-01');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testInvalidEndDate(): void
    {
        $result = ($this->tool)('2023-01-01', 'invalid-date');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testInvalidTimezone(): void
    {
        $result = ($this->tool)('2023-01-01', '2023-01-02', null, 'Invalid/Timezone');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testToolProperties(): void
    {
        $this->assertEquals('date_difference', $this->tool->getName());
        $this->assertEquals('Calculate the difference between two dates in various units', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(4, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('start_date', $propertyNames);
        $this->assertContains('end_date', $propertyNames);
        $this->assertContains('unit', $propertyNames);
        $this->assertContains('timezone', $propertyNames);
    }
}
